<?php
// ToolsByDcx bot handler — replicates @Bite_storee_bot per the analysis.
// All shop content comes from the database; texts via I18n (English fallback).
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/BinancePay.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/howto.php';

class Bot {
    private array $bot;
    private string $token;
    private I18n $i;
    private ?array $user = null;
    private int $chatId = 0;

    public function __construct(array $bot) {
        $this->bot = $bot;
        $this->token = dec_token($bot['token_enc']);
        $this->i = new I18n((int)$bot['id'], 'en');
    }

    // ---------- entry ----------
    public function handle(array $update): void {
        if (isset($update['pre_checkout_query'])) { $this->onPreCheckout($update['pre_checkout_query']); return; }
        if (isset($update['message']['successful_payment'])) {
            $this->onSuccessfulPayment($update['message']); return;
        }
        if (isset($update['callback_query'])) { $this->onCallback($update['callback_query']); return; }
        if (isset($update['message'])) {
            $this->onMessage($update['message']);
            // Issue 34: delete the user's command/tap message AFTER the bot
            // has answered, so only the bot's screen stays visible at the
            // bottom of the chat (like the original bot). In a private chat
            // the bot may delete incoming messages; a failure (e.g. too old,
            // >48h) is harmless. Deletion runs only when handling succeeded
            // — if onMessage throws, the message is kept.
            tg_delete($this->token, $this->chatId, (int)$update['message']['message_id']);
        }
    }

    // ---------- users ----------
    private function getUser(array $from): array {
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM users WHERE bot_id=? AND telegram_id=?");
        $st->execute([$this->bot['id'], $from['id']]);
        $u = $st->fetch();
        if (!$u) {
            $pdo->prepare("INSERT INTO users (bot_id, telegram_id, username, first_name) VALUES (?,?,?,?)")
                ->execute([$this->bot['id'], $from['id'], $from['username'] ?? '', $from['first_name'] ?? '']);
            $st->execute([$this->bot['id'], $from['id']]);
            $u = $st->fetch();
        }
        $this->i->setLang($u['language'] ?? 'en');
        $pdo->prepare("UPDATE users SET last_active=NOW() WHERE id=?")->execute([$u['id']]);
        $this->user = $u;
        return $u;
    }
    private function refreshUser(): void {
        $st = db()->prepare("SELECT * FROM users WHERE id=?");
        $st->execute([$this->user['id']]);
        $this->user = $st->fetch();
        $this->i->setLang($this->user['language']);
    }
    private function setState(string $state, array $data = []): void {
        // Preserve the tracked menu message across state changes: the
        // state machine must never wipe the screen tracking.
        $cur = $this->stateData();
        foreach (['menu_msg_id', 'menu_media', 'menu_is_banner', 'menu_image', 'kb_sent', 'kb_msg_id', 'kb_sent_at', 'copy_msg_id'] as $k) {
            if (!array_key_exists($k, $data) && array_key_exists($k, $cur)) $data[$k] = $cur[$k];
        }
        db()->prepare("UPDATE users SET state=?, state_data=? WHERE id=?")
            ->execute([$state, json_encode($data), $this->user['id']]);
        $this->user['state'] = $state;
        $this->user['state_data'] = json_encode($data);
    }
    private function stateData(): array {
        return json_decode($this->user['state_data'] ?? '{}', true) ?: [];
    }

    // ---------- tracked menu message ----------
    // The id (+ media flag) of the user's current screen message, kept in
    // users.state_data so navigation can EDIT it in place instead of
    // stacking fresh messages below. No schema change needed.
    private function menuMsg(): array {
        $d = $this->stateData();
        return ['id' => (int)($d['menu_msg_id'] ?? 0), 'media' => !empty($d['menu_media']), 'banner' => !empty($d['menu_is_banner']), 'image' => (string)($d['menu_image'] ?? '')];
    }
    private function menuTrack(int $id, bool $media, ?bool $isBanner = null, ?string $imageUrl = null): void {
        $d = $this->stateData();
        $d['menu_msg_id'] = $id;
        $d['menu_media'] = $media ? 1 : 0;
        if ($isBanner !== null) $d['menu_is_banner'] = $isBanner ? 1 : 0;
        if ($imageUrl !== null) $d['menu_image'] = $imageUrl;
        db()->prepare("UPDATE users SET state_data=? WHERE id=?")
            ->execute([json_encode($d), $this->user['id']]);
        $this->user['state_data'] = json_encode($d);
    }
    // The copy message (e.g. "Payment address — tap to copy") is an extra
    // message, not the tracked menu screen, so navigation would leave it
    // sitting in the chat. Track it and delete it on any navigation, the
    // same way the tracked menu message is cleaned up.
    private function trackCopyMsg(int $id): void {
        $d = $this->stateData();
        $d['copy_msg_id'] = $id;
        db()->prepare("UPDATE users SET state_data=? WHERE id=?")
            ->execute([json_encode($d), $this->user['id']]);
        $this->user['state_data'] = json_encode($d);
    }
    private function deleteCopyMsg(): void {
        $d = $this->stateData();
        $id = (int)($d['copy_msg_id'] ?? 0);
        if ($id > 0) {
            tg_api($this->token, 'deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $id]);
            $d['copy_msg_id'] = 0;
            db()->prepare("UPDATE users SET state_data=? WHERE id=?")
                ->execute([json_encode($d), $this->user['id']]);
            $this->user['state_data'] = json_encode($d);
        }
    }
    // Show a text screen as a FRESH message at the bottom of the chat.
    // This is used for screens triggered by a text message (a hamburger
    // command or a reply-keyboard tap): the user is looking at the bottom
    // of the chat, where their command and the recent messages are, so
    // editing the old tracked banner (which sits above those messages)
    // would be invisible — the user would press a command, it would run
    // up top, and they would see nothing happen. The old tracked menu
    // message is deleted first so the chat does not pile up, then the new
    // screen is sent at the bottom and becomes the tracked one. Inline
    // button taps use the $edit closure instead (they edit in place,
    // because the user is looking at the banner when they tap it).
    private function showTracked(string $text, $kb): void {
        $this->deleteCopyMsg();
        $m = $this->menuMsg();
        if ($m['id'] > 0) {
            tg_api($this->token, 'deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $m['id']]);
        }
        $res = tg_send($this->token, $this->chatId, $text, $kb);
        if ($res['ok'] ?? false) $this->menuTrack((int)$res['result']['message_id'], false);
    }

    // Show a list screen (categories, category products, price list).
    // The user wants no delete animation when the current screen is the
    // welcome banner: editing the banner's caption in place is smooth and
    // instant. The delete + fresh send only happens when the current
    // screen is already a text message (or a product photo), where an
    // edit in place would leave the wrong media or a stale text message.
    // A fresh send auto-scrolls the chat to the list; an in-place edit
    // does not, so the banner case may still need a manual scroll for a
    // very long list — that is the trade-off the user chose over the
    // delete animation.
    private function showListScreen(string $txt, $kb, callable $edit): void {
        $m = $this->menuMsg();
        if ($m['media'] && $m['banner']) {
            $edit($txt, $kb);
            return;
        }
        $this->showTracked($txt, $kb);
    }

    // Show a screen with its own image (product / category photo). The
    // tracked message is the welcome banner (an animation), and editing
    // its caption keeps the banner GIF — so a product photo can only be
    // shown by replacing the message: delete the tracked one and send a
    // fresh photo message at the bottom (which also auto-scrolls the chat
    // to it). The new message is tracked as media, not the banner, so
    // sendWelcome knows to restore the banner GIF when going Home.
    // Returns true when a photo message was sent.
    private function showPhotoScreen(string $imageUrl, string $caption, $kb, ?callable $edit = null): bool {
        $this->deleteCopyMsg();
        $m = $this->menuMsg();
        // Same photo already on screen (e.g. Next/Prev inside one
        // category): only the caption and buttons change, so edit them in
        // place. No delete, no animation, no re-upload — smooth paging.
        if ($m['id'] > 0 && $m['media'] && !$m['banner'] && ($m['image'] ?? '') === $imageUrl && $edit) {
            $edit($caption, $kb);
            return true;
        }
        // A media message is on screen (banner GIF or another photo): swap
        // the photo in place instead of deleting and resending it.
        if ($m['id'] > 0 && $m['media']) {
            $res = tg_edit_media($this->token, $this->chatId, $m['id'], $imageUrl, $caption, $kb);
            if ($res['ok'] ?? false) {
                $this->menuTrack($m['id'], true, false, $imageUrl);
                return true;
            }
        }
        // Text screen below (or nothing tracked): delete it and send the
        // photo fresh at the bottom so it auto-scrolls into view.
        if ($m['id'] > 0) {
            tg_api($this->token, 'deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $m['id']]);
        }
        $res = tg_photo($this->token, $this->chatId, $imageUrl, $caption, $kb);
        if ($res['ok'] ?? false) {
            $this->menuTrack((int)$res['result']['message_id'], true, false, $imageUrl);
            return true;
        }
        // The image URL failed (bad link / Telegram could not fetch it):
        // fall back to a plain text screen so the user still sees the
        // product instead of an error.
        $r2 = tg_send($this->token, $this->chatId, $caption, $kb);
        if ($r2['ok'] ?? false) $this->menuTrack((int)$r2['result']['message_id'], false, false, '');
        return false;
    }

    // ---------- keyboards ----------
    private function mainMenuKb(): array {
        $i = $this->i;
        // Button colours copied from the original bot (Bot API 9.4 styles):
        // primary = blue, success = green.
        return inline_kb([
            [btn($i->t('btn_shop'), 'm:shop', 'primary')],
            [btn($i->t('btn_points'), 'm:points', 'primary'), btn($i->t('btn_price'), 'm:price', 'primary')],
            [btn($i->t('btn_account'), 'm:acct', 'success'), btn($i->t('btn_orders'), 'm:orders', 'primary'), btn($i->t('btn_txns'), 'm:txns', 'success')],
            [btn($i->t('btn_loyalty'), 'm:loyal', 'primary'), btn($i->t('btn_refer'), 'm:refer', 'primary')],
            [btn($i->t('btn_support'), 'm:support', 'success'), btn($i->t('btn_warranty'), 'm:war', 'primary'), btn($i->t('btn_reviews'), 'm:rev', 'success')],
            [btn($i->t('btn_language'), 'm:lang', 'primary')],
            // Issue 35: Freebies / Reseller API / How to Use used to live only
            // on the bottom reply keyboard, whose carrier bubble ("👇 🏠 Menu")
            // the user wants gone. They now live here on the banner instead.
            [btn($i->t('btn_freebies'), 'm:freebies', 'success'), btn($i->t('btn_api'), 'm:api', 'success'), btn($i->t('btn_howto'), 'm:howto', 'primary')],
        ]);
    }
    private function replyKb(): array {
        $i = $this->i;
        return reply_kb([
            [btn_kb($i->t('btn_menu'), 'success'), btn_kb($i->t('btn_api'), 'success')],
            [btn_kb($i->t('btn_freebies'), 'success'), btn_kb($i->t('btn_howto'), 'primary')],
        ]);
    }
    // Button colours copied from the original bot (Bot API 9.4 styles):
    // primary = blue, success = green, danger = red. Grids alternate
    // blue/green so no button is left in Telegram's default dark style.
    private function altStyle(int $i): string { return $i % 2 === 0 ? 'primary' : 'success'; }
    private function backHomeKb(): array {
        return inline_kb([[btn($this->i->t('btn_back'), 'm:home', 'primary'), btn($this->i->t('btn_home'), 'm:home', 'success')]]);
    }

    // Retry screen for a payment check: a Check Payment button on top, so
    // the user can tap it again right here instead of going back to find it.
    private function checkPaymentKb(string $type, int $refId): array {
        return inline_kb([
            [btn('🔍 Check Payment', 'pc:' . $type . ':' . $refId, 'primary')],
            [btn($this->i->t('btn_back'), 'm:home', 'primary'), btn($this->i->t('btn_home'), 'm:home', 'success')],
        ]);
    }

    // ---------- messages ----------
    private function onMessage(array $msg): void {
        $this->chatId = (int)$msg['chat']['id'];
        $from = $msg['from'];
        $user = $this->getUser($from);
        $text = trim($msg['text'] ?? '');
        if ($user['banned']) { tg_send($this->token, $this->chatId, '⛔ Your account is banned. Contact support.'); return; }

        // State machine inputs first
        if ($user['state'] !== '' && !str_starts_with($text, '/')) {
            if ($this->handleState($text)) return;
        }
        if (str_starts_with($text, '/')) {
            $parts = explode(' ', $text, 2);
            $cmd = strtolower($parts[0]);
            $arg = $parts[1] ?? '';
            match ($cmd) {
                '/start' => $this->cmdStart($arg),
                '/balance' => $this->showAccount(),
                '/deposit' => $this->showPoints(),
                '/orders' => $this->showOrders('all', 1),
                '/freebies' => $this->showFreebies(),
                '/apikey' => $this->showApi(),
                '/language' => $this->showLanguage(),
                '/support' => $this->showSupport(),
                '/help' => $this->showHowTo(),
                default => $this->sendWelcome(true),
            };
            return;
        }
        // Reply keyboard (match translated text and English fallback).
        // NOTE: arrays cannot be array keys in PHP — the map is a list of
        // [labels, action] pairs, not an associative array.
        $en = new I18n((int)$this->bot['id'], 'en');
        $map = [
            [[$this->i->t('btn_menu'), $en->t('btn_menu')], 'welcome'],
            [[$this->i->t('btn_api'), $en->t('btn_api')], 'api'],
            [[$this->i->t('btn_freebies'), $en->t('btn_freebies')], 'freebies'],
            [[$this->i->t('btn_howto'), $en->t('btn_howto')], 'howto'],
        ];
        foreach ($map as [$labels, $action]) {
            if (in_array($text, $labels, true)) {
                match ($action) {
                    'welcome' => $this->sendWelcome(true),
                    'api' => $this->showApi(),
                    'freebies' => $this->showFreebies(),
                    'howto' => $this->showHowTo(),
                };
                return;
            }
        }
        $this->sendWelcome(true);
    }

    private function cmdStart(string $arg): void {
        if ($arg !== '' && is_numeric($arg)) {
            $this->handleReferral((int)$arg);
        }
        $this->sendWelcome(true);
    }

    private function handleReferral(int $referrerTgId): void {
        if ($referrerTgId === (int)$this->user['telegram_id']) return;
        $pdo = db();
        $st = $pdo->prepare("SELECT id FROM users WHERE bot_id=? AND telegram_id=?");
        $st->execute([$this->bot['id'], $referrerTgId]);
        $ref = $st->fetch();
        if (!$ref) return;
        // Only first-time users (joined within last 60s and no referrer yet)
        if ($this->user['referred_by']) return;
        $pdo->prepare("INSERT IGNORE INTO referrals (bot_id, referrer_id, referred_id) VALUES (?,?,?)")
            ->execute([$this->bot['id'], $ref['id'], $this->user['id']]);
        $pdo->prepare("UPDATE users SET referred_by=? WHERE id=? AND referred_by IS NULL")
            ->execute([$ref['id'], $this->user['id']]);
        // Math verification question
        $a = random_int(2, 9); $b = random_int(2, 9);
        $this->setState('await_math', ['a' => $a, 'b' => $b, 'referrer' => $ref['id']]);
        tg_send($this->token, $this->chatId,
            "🔐 Verification: to activate your referral, please answer: What is {$a} + {$b}?");
    }

    private function sendWelcome(bool $fresh = false): void {
        $this->deleteCopyMsg();
        // Edit the tracked menu message in place when it is the banner, so
        // repeated Menu / /start taps never stack fresh banners below.
        // Only send a fresh banner when there is no tracked message or it
        // can no longer be edited (deleted / too old).
        // Issue 33: /start always sends a FRESH banner. The tracked message
        // can sit in old history the client no longer loads (after the user
        // clears the chat), so editing it succeeds ("not modified") yet the
        // user sees nothing and thinks the bot is dead. A fresh /start must
        // appear at the bottom of the chat, visibly.
        $m = $this->menuMsg();
        // Issue 38: the chat-list preview shows the banner caption, so the
        // caption must be short. The DB welcome_caption still holds the old
        // long "Account User ID" text (SSH is down, so the live DB cannot be
        // updated from here), and the preview kept showing that text. Use a
        // short caption in code so pulling alone fixes the preview; the
        // Account screen still shows the user ID / points / balance.
        $caption = '👋 Welcome to ToolsByDcx';
        // Only edit in place when the tracked message IS the banner. A
        // product/category photo is also a media message, but editing its
        // caption would keep the product photo with a welcome caption —
        // so a photo screen is deleted and a fresh banner is sent instead.
        if (!$fresh && $m['id'] > 0 && $m['media'] && $m['banner']) {
            $res = tg_edit_caption($this->token, $this->chatId, $m['id'], $caption, $this->mainMenuKb());
            if ($res['ok'] ?? false) {
                $this->menuTrack((int)($res['result']['message_id'] ?? $m['id']), !empty($res['_fallback_text']) ? false : true);
                $this->showReplyKbHidden();
                return;
            }
            if (str_contains((string)($res['description'] ?? ''), 'message is not modified')) {
                $this->showReplyKbHidden();
                return;
            }
        }
        if ($m['id'] > 0) {
            tg_api($this->token, 'deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $m['id']]);
        }
        $cfg = load_config();
        $base = rtrim($cfg['base_url'], '/');
        // Animated GIF banner when present (like the original), else static PNG.
        // Uploaded as bytes: passing a URL made Telegram fetch the file from
        // our server, which was the slowest step of /start.
        $gifOnDisk = dirname(__DIR__) . '/public/assets/banner.gif';
        $pngOnDisk = dirname(__DIR__) . '/public/assets/banner.png';
        if (is_file($gifOnDisk)) {
            $res = tg_animation_file($this->token, $this->chatId, $gifOnDisk, $caption, $this->mainMenuKb());
        } elseif (is_file($pngOnDisk)) {
            $res = tg_photo_file($this->token, $this->chatId, $pngOnDisk, $caption, $this->mainMenuKb());
        } else {
            $res = tg_animation($this->token, $this->chatId, $base . '/assets/banner.gif', $caption, $this->mainMenuKb());
        }
        if ($res['ok'] ?? false) $this->menuTrack((int)$res['result']['message_id'], true, true);
        $this->showReplyKbHidden();
    }

    // (Re)send the persistent reply keyboard's carrier message when
    // needed: the first time, or when the last carrier is older than 15
    // minutes — mobile clients can lose the keyboard when it is dismissed
    // or the app restarts, and the bot must be able to bring it back.
    // The old carrier is deleted first, so there is still exactly one
    // carrier message. Called on EVERY welcome path (including the
    // already-showing one): an early return above must never skip it.
    // Issue 35: the reply-keyboard carrier ("👇 🏠 Menu" bubble) is gone.
    // It used to deliver the bottom buttons, but the user wants that bubble
    // removed. Freebies / Reseller API / How to Use now live on the banner's
    // inline menu instead. This only deletes the old carrier message (if any)
    // and clears the keyboard state; it never sends a new carrier.
    // Issue 37 (final v3): the bottom reply keyboard (Menu / Reseller API /
    // Freebies / How to Use) NEEDS a carrier message to exist. Deleting the
    // carrier removes the keyboard with it — the user proved this 12:10 PKT
    // The reply keyboard's carrier message. The original bot does NOT send
    // a carrier on /start — it sends only the banner, and the bottom
    // buttons persist from the keyboard set the very first time. So the
    // carrier is sent ONCE ever (the first /start, kb_sent == 0) and never
    // again: no refresh, no re-send. The keyboard stays as a chat-level
    // element, and the carrier sits in the chat history, never at the
    // bottom. The carrier text is just the bot name in plain text (no
    // emoji, no "Menu", no finger) so it is a small, clean bubble, never
    // a giant emoji. IMPORTANT: the user must not delete this message, or
    // the 4 buttons go away again — the original's buttons stay only
    // because its carrier message is still in the chat, never deleted.
    private function showReplyKbHidden(): void {
        $d = $this->stateData();
        if (!empty($d['kb_sent'])) return;
        $res = tg_send($this->token, $this->chatId, 'ToolsByDcx', $this->replyKb());
        if ($res['ok'] ?? false) {
            $d['kb_msg_id'] = (int)$res['result']['message_id'];
            $d['kb_sent_at'] = time();
            $d['kb_sent'] = 1;
            db()->prepare("UPDATE users SET state_data=? WHERE id=?")
                ->execute([json_encode($d), $this->user['id']]);
            $this->user['state_data'] = json_encode($d);
        }
    }

    // ---------- state machine ----------
    private function handleState(string $text): bool {
        $state = $this->user['state'];
        $data = $this->stateData();
        if ($state === 'await_math') {
            $want = $data['a'] + $data['b'];
            if ((int)$text === $want) {
                $this->approveReferral((int)$data['referrer']);
                $this->setState('');
                tg_send($this->token, $this->chatId, '✅ Verified! Referral activated. You and your referrer both earned points.');
            } else {
                tg_send($this->token, $this->chatId, '❌ Wrong answer. Please try again: What is ' . $data['a'] . ' + ' . $data['b'] . '?');
            }
            return true;
        }
        if (str_starts_with($state, 'await_qty:')) {
            $pid = (int)substr($state, 10);
            $qty = (int)$text;
            if ($qty < 1 || $qty > 100) { tg_send($this->token, $this->chatId, '❌ Enter a quantity between 1 and 100.'); return true; }
            $this->setState('');
            $this->showConfirm($pid, $qty);
            return true;
        }
        if ($state === 'await_custom_points') {
            $usd = (float)$text;
            if ($usd < 1) { tg_send($this->token, $this->chatId, '❌ Enter an amount of $1 or more.'); return true; }
            $this->setState('');
            $this->showDepositMethods($usd);
            return true;
        }
        // Ticket flow (original bot method): Name -> Product picker -> Issue.
        // Step 1 asks for the name; Step 2 is a paginated product picker
        // (or General / Other Inquiry); Step 3 asks for the issue text.
        // There is no separate subject step — the ticket subject is
        // "Name — Product". On submit the step messages are deleted and
        // the main menu returns (clean chat, issue 40).
        if ($state === 'await_support_name') {
            $msgs = $data['tic_msgs'] ?? [];
            [$txt, $kb] = $this->ticketPicker(1, $text);
            $r = tg_send($this->token, $this->chatId, $txt, $kb);
            if ($r['ok'] ?? false) {
                $msgs[] = (int)$r['result']['message_id'];
            }
            $this->setState('await_support_product', ['name' => $text, 'tic_msgs' => $msgs]);
            return true;
        }
        if ($state === 'await_support_search') {
            $msgs = $data['tic_msgs'] ?? [];
            $pickerId = (int)end($msgs);
            [$txt, $kb] = $this->ticketPicker(1, $data['name'] ?? '', $text);
            if ($pickerId > 0) tg_edit($this->token, $this->chatId, $pickerId, $txt, $kb);
            $this->setState('await_support_product', ['name' => $data['name'] ?? '', 'tic_msgs' => $msgs, 'query' => $text]);
            return true;
        }
        if ($state === 'await_support_msg') {
            $pdo = db();
            // Original bot: the ticket subject is just the product
            // ("General / Account Inquiry"), not "Name — Product". The
            // name the user entered is shown in the flow, and the admin
            // panel shows who the ticket is from.
            $product = $data['product'] ?? 'General / Account Inquiry';
            $pdo->prepare("INSERT INTO support_tickets (bot_id, user_id, subject) VALUES (?,?,?)")
                ->execute([$this->bot['id'], $this->user['id'], $product]);
            $tid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender, message) VALUES (?, 'user', ?)")
                ->execute([$tid, $text]);
            $this->setState('');
            foreach (($data['tic_msgs'] ?? []) as $mid) {
                tg_delete($this->token, $this->chatId, (int)$mid);
            }
            $this->sendWelcome(true);
            return true;
        }
        if (str_starts_with($state, 'await_warranty:')) {
            $oid = (int)substr($state, 15);
            $type = $data['type'] ?? 'warranty';
            $own = db()->prepare("SELECT 1 FROM orders WHERE id=? AND bot_id=? AND user_id=?");
            $own->execute([$oid, $this->bot['id'], $this->user['id']]);
            if (!$own->fetch()) { $this->setState(''); tg_send($this->token, $this->chatId, '❌ Order not found.'); return true; }
            db()->prepare("INSERT INTO warranty_claims (bot_id, order_id, user_id, type, reason) VALUES (?,?,?,?,?)")
                ->execute([$this->bot['id'], $oid, $this->user['id'], $type, $text]);
            $this->setState('');
            tg_send($this->token, $this->chatId, '✅ Your ' . $type . ' claim for order #' . $oid . ' was submitted. Admin review takes 24-48 hours.');
            return true;
        }
        if (str_starts_with($state, 'await_review:')) {
            $pid = (int)substr($state, 13);
            $rating = (int)($data['rating'] ?? 5);
            db()->prepare("INSERT INTO reviews (bot_id, product_id, user_id, rating, text) VALUES (?,?,?,?,?)")
                ->execute([$this->bot['id'], $pid, $this->user['id'], $rating, $text]);
            $this->setState('');
            tg_send($this->token, $this->chatId, '⭐ Thank you! Your review was submitted for moderation.');
            return true;
        }
        if (str_starts_with($state, 'await_binance_order:')) {
            // Typed: await_binance_order:o:<order id> or await_binance_order:d:<deposit id>.
            // The user pasted the Order ID Binance showed them after paying.
            $p = explode(':', $state);
            $type = $p[1] ?? 'o';
            $oid = (int)($p[2] ?? 0);
            $orderId = trim($text);
            $table = $type === 'd' ? 'deposits' : 'orders';
            $st = db()->prepare("SELECT * FROM $table WHERE id=? AND bot_id=? AND user_id=?");
            $st->execute([$oid, $this->bot['id'], $this->user['id']]);
            $o = $st->fetch();
            if (!$o) { $this->setState(''); tg_send($this->token, $this->chatId, '❌ Payment reference not found.'); return true; }
            $details = $this->paymentDetails('binance');
            $createdMs = strtotime((string)$o['created_at']) * 1000;
            $startMs = max(0, $createdMs - 120000);
            $endMs = min((int)round(microtime(true) * 1000), $createdMs + (30 * 60 * 1000));
            $currency = strtoupper(trim((string)($details['currency'] ?? 'USDT')));
            $usedSt = db()->prepare("SELECT txid FROM orders WHERE bot_id=? AND txid<>'' UNION ALL SELECT txid FROM deposits WHERE bot_id=? AND txid<>''");
            $usedSt->execute([$this->bot['id'], $this->bot['id']]);
            $usedTxids = array_values(array_filter(array_map('strval', $usedSt->fetchAll(PDO::FETCH_COLUMN))));
            $res = BinancePay::findPaymentByOrderId($details, $orderId, $currency, $startMs, $endMs, $usedTxids);
            $this->setState('');
            if (!($res['ok'] ?? false)) {
                tg_send($this->token, $this->chatId, '⏳ Binance Pay verification is temporarily unavailable. Please try Check Payment again shortly.');
                return true;
            }
            if (!($res['found'] ?? false)) {
                tg_send($this->token, $this->chatId, '❌ No payment found for Order ID ' . esc($orderId) . '. Check the Order ID and try again.', inline_kb([[btn('🔍 Check Payment', 'pc:' . $type . ':' . $oid, 'primary'), btn($this->i->t('btn_home'), 'm:home', 'success')]]));
                return true;
            }
            $transaction = is_array($res['transaction'] ?? null) ? $res['transaction'] : [];
            $txid = trim((string)($transaction['transactionId'] ?? $transaction['orderId'] ?? ''));
            if ($type === 'd') {
                $this->confirmBinanceDeposit($oid, $txid);
            } else {
                $p = $this->product((int)$o['product_id']);
                if (!$p) { tg_send($this->token, $this->chatId, '❌ Product no longer exists. Contact support with this order number.'); return true; }
                $this->fulfillExistingOrder($oid, $p, (int)$o['qty'], $txid);
            }
            return true;
        }
        if (str_starts_with($state, 'await_txid:')) {
            // Typed: await_txid:o:<order id> or await_txid:d:<deposit id>.
            $p = explode(':', $state);
            $type = $p[1] ?? 'o';
            $oid = (int)($p[2] ?? 0);
            if ($type === 'd') {
                db()->prepare("UPDATE deposits SET txid=? WHERE id=? AND bot_id=? AND user_id=? AND status='pending'")
                    ->execute([$text, $oid, $this->bot['id'], $this->user['id']]);
            } else {
                db()->prepare("UPDATE orders SET txid=? WHERE id=? AND bot_id=? AND user_id=? AND status='pending'")
                    ->execute([$text, $oid, $this->bot['id'], $this->user['id']]);
            }
            $this->setState('');
            tg_send($this->token, $this->chatId, "✅ TXID recorded for #{$oid}. An admin will verify your payment shortly.");
            return true;
        }
        return false;
    }

    private function approveReferral(int $referrerId): void {
        $pdo = db();
        $reward = (float)setting($this->bot['id'], 'referral_reward', 0.1);
        $st = $pdo->prepare("SELECT * FROM referrals WHERE bot_id=? AND referrer_id=? AND referred_id=? AND status='pending'");
        $st->execute([$this->bot['id'], $referrerId, $this->user['id']]);
        $ref = $st->fetch();
        if (!$ref) return;
        $pdo->prepare("UPDATE referrals SET status='approved', points_awarded=? WHERE id=?")->execute([$reward, $ref['id']]);
        add_points($this->bot['id'], $referrerId, $reward, 'referral_earned', 'referral', $ref['id']);
        add_points($this->bot['id'], $this->user['id'], $reward, 'referral_joined', 'referral', $ref['id']);
        // Milestone: 20 approved refs -> +10 pts
        $milestone = (int)setting($this->bot['id'], 'referral_milestone', 20);
        $mstReward = (float)setting($this->bot['id'], 'referral_milestone_reward', 10);
        $c = $pdo->prepare("SELECT COUNT(*) c FROM referrals WHERE bot_id=? AND referrer_id=? AND status='approved'");
        $c->execute([$this->bot['id'], $referrerId]);
        if ((int)$c->fetch()['c'] === $milestone) {
            add_points($this->bot['id'], $referrerId, $mstReward, 'referral_milestone', 'referral', $ref['id']);
        }
    }

    // ---------- screens (message versions) ----------
    private function showAccount(): void {
        $u = $this->user;
        $points = (float)$u['points_wallet'] + (float)$u['points_referral'];
        $st = db()->prepare("SELECT COUNT(*) c FROM referrals WHERE bot_id=? AND referrer_id=? AND status='approved'");
        $st->execute([$this->bot['id'], $u['id']]);
        $refs = (int)$st->fetch()['c'];
        $txt = $this->i->t('account_title', [
            'name' => esc($u['first_name']), 'username' => esc($u['username']) ?: '—',
            'user_id' => $u['telegram_id'], 'joined' => substr($u['joined_at'], 0, 10),
            'points' => fmt_points($points), 'wallet' => fmt_points($u['points_wallet']),
            'referral' => fmt_points($u['points_referral']),
            'balance' => fmt_usd($points / (float)setting($this->bot['id'], 'points_rate', 10)),
            'referrals' => $refs, 'status' => 'Active',
        ]);
        $this->showTracked($txt, $this->backHomeKb());
    }

    private function showPoints(): void {
        $points = (float)$this->user['points_wallet'] + (float)$this->user['points_referral'];
        $rate = setting($this->bot['id'], 'points_rate', 10);
        $txt = $this->i->t('points_title', ['points' => fmt_points($points), 'rate' => $rate]);
        $kb = inline_kb([
            [btn('$1', 'pp:1', 'primary'), btn('$5', 'pp:5', 'success'), btn('$10', 'pp:10', 'primary'), btn('$25', 'pp:25', 'success')],
            [btn('✏️ Custom Amount', 'pp:custom', 'primary')],
            [btn($this->i->t('btn_back'), 'm:home', 'primary')],
        ]);
        $this->showTracked($txt, $kb);
    }

    private function showDepositMethods(float $usd, $edit = null): void {
        $labels = [
            'binance' => ['Binance Auto Pay', 'primary'],
            'bep20' => ['USDT (BEP20)', 'success'],
            'sol' => ['USDT (Solana)', 'primary'],
            'ton' => ['USDT (TON)', 'success'],
            'stars' => ['⭐ Telegram Stars', 'primary'],
        ];
        $rows = [];
        $pair = [];
        foreach ($this->activePaymentMethods() as $code) {
            if (!isset($labels[$code])) continue;
            [$label, $style] = $labels[$code];
            $pair[] = btn($label, 'dp:' . $code . ':' . $usd, $style);
            if (count($pair) === 2) { $rows[] = $pair; $pair = []; }
        }
        if ($pair) $rows[] = $pair;
        if (!$rows) {
            $msg = '❌ No payment methods are available right now. Please contact the store admin.';
            if ($edit) $edit($msg, $this->backHomeKb());
            else $this->showTracked($msg, $this->backHomeKb());
            return;
        }
        $rows[] = [btn($this->i->t('btn_back'), 'm:points', 'primary')];
        $kb = inline_kb($rows);
        $txt = "💰 Deposit \${$usd} (" . fmt_points(points_for($this->bot['id'], $usd)) . " points)\n\nSelect payment method:";
        if ($edit) $edit($txt, $kb);
        else $this->showTracked($txt, $kb);
    }

    // Active payment methods for this bot, in sort order. The admin
    // panel toggles payment_methods.active — a method turned off there
    // must not appear in the bot. Returns e.g. ['points','binance','stars'].
    private function activePaymentMethods(): array {
        $st = db()->prepare("SELECT code FROM payment_methods WHERE bot_id=? AND active=1 ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function paymentMethodActive(string $code): bool {
        return in_array($code, $this->activePaymentMethods(), true);
    }

    private function paymentDetails(string $method): array {
        $st = db()->prepare("SELECT details FROM payment_methods WHERE bot_id=? AND code=? AND active=1");
        $st->execute([$this->bot['id'], $method]);
        return json_decode((string)($st->fetch()['details'] ?? '{}'), true) ?: [];
    }

    private function binanceTradeNo(string $type, int $id): string {
        return 'TDCX' . (int)$this->bot['id'] . ($type === 'd' ? 'D' : 'O') . $id;
    }

    // Personal Binance Pay: the store owner's personal account (API key +
    // secret + UID). A personal API key CANNOT create a receive link or a
    // merchant order via the API — the C2C receive endpoint needs OAuth
    // and the merchant order endpoint needs a merchant key — so the bot
    // does not try to create a payment link. Instead it shows the owner's
    // Binance Pay UID and the exact amount, the customer sends it manually
    // in the Binance app, and the bot verifies the payment ONLY by the
    // Binance Order ID the customer pastes (findPaymentByOrderId) — the
    // UID, currency and Order ID must all match (the exact amount is not
    // checked, by the owner's decision).
    private function binancePersonal(array $details): bool {
        return true;
    }

    private function trustedBinancePayLink(string $url): bool {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        return in_array($host, ['app.binance.com', 'www.binance.com'], true)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https';
    }

    private function createBinanceOrder(array $p, int $qty, float $total, callable $edit): void {
        $pdo = db();
        $pdo->prepare("INSERT INTO orders (bot_id, user_id, product_id, qty, unit_price, total_usd, status, payment_method) VALUES (?,?,?,?,?,?, 'pending', 'binance')")
            ->execute([$this->bot['id'], $this->user['id'], $p['id'], $qty, $p['price_usd'], $total]);
        $oid = (int)$pdo->lastInsertId();
        $tradeNo = $this->binanceTradeNo('o', $oid);
        $details = $this->paymentDetails('binance');
        if (!BinancePay::configured($details)) {
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
            $edit('❌ Binance Pay is not configured yet. Please contact the store admin.', $this->backHomeKb());
            return;
        }
        if ($this->binancePersonal($details)) {
            // Personal account: no API-created link is possible (the C2C
            // receive endpoint needs OAuth, the merchant order endpoint
            // needs a merchant key). Show the owner's Binance Pay UID and
            // the exact amount; the customer sends it in the Binance app
            // and the bot verifies it from the Pay history on Check Payment.
            $uid = trim((string)($details['uid'] ?? ''));
            if ($uid === '') {
                $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
                $edit('❌ Binance Pay is not configured yet. Please contact the store admin.', $this->backHomeKb());
                return;
            }
            $currency = strtoupper(trim((string)($details['currency'] ?? 'USDT')));
            $txt = "🟡 Binance Pay\n\nOrder #{$oid}\nAmount: $" . fmt_usd($total) . " {$currency}\n\nSend exactly $" . fmt_usd($total) . " {$currency} to this Binance Pay UID in your Binance app:\n\n<code>{$uid}</code>\n\nAfter sending, tap Check Payment and paste the Order ID Binance showed you.";
            // copy_text buttons copy straight to the clipboard on tap —
            // no callback, no extra message. Copy Details fits the 256
            // char limit (UID + amount + currency).
            $copyDetails = "Binance Pay UID: {$uid}\nAmount: $" . fmt_usd($total) . " {$currency}";
            $kb = inline_kb([
                [btn_copy('📋 Copy UID', $uid, 'primary'), btn_copy('📋 Copy Details', $copyDetails, 'success')],
                [btn('🔍 Check Payment', 'pc:o:' . $oid, 'primary'), btn('❌ Cancel Payment', 'px:o:' . $oid, 'danger')],
            ]);
            $edit($txt, $kb);
            return;
        }
        $res = BinancePay::createOrder($details, $tradeNo, $total, (string)$p['name'], 'product-' . (int)$p['id']);
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $url = (string)($data['checkoutUrl'] ?? $data['universalUrl'] ?? $data['deeplink'] ?? '');
        if (($res['status'] ?? '') !== 'SUCCESS' || $url === '') {
            error_log('Binance create order failed: ' . json_encode(['code' => $res['code'] ?? '', 'message' => $res['errorMessage'] ?? $res['error'] ?? 'unknown']));
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
            $edit('❌ Binance Pay could not start this payment. Please try again or choose another method.', $this->backHomeKb());
            return;
        }
        $pdo->prepare("UPDATE orders SET binance_trade_no=?, binance_prepay_id=? WHERE id=?")
            ->execute([$tradeNo, (string)($data['prepayId'] ?? ''), $oid]);
        $txt = "🟡 Binance Pay\n\nOrder #{$oid}\nAmount: $" . fmt_usd($total) . " USDT\n\nOpen Binance Pay, complete the payment, then tap Check Payment. Delivery is automatic after Binance confirms the payment.";
        $kb = inline_kb([
            [btn_url('🟡 Open Binance Pay', $url, 'success')],
            [btn('🔍 Check Payment', 'pc:o:' . $oid, 'primary'), btn('❌ Cancel Payment', 'px:o:' . $oid, 'danger')],
        ]);
        $edit($txt, $kb);
    }

    private function showFreebies(): void {
        $st = db()->prepare("SELECT * FROM freebies WHERE bot_id=? AND active=1 ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $f) $rows[] = [btn($f['title'], 'free:' . $f['id'], $this->altStyle($i++))];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $this->showTracked($this->i->t('freebies_title'), inline_kb($rows));
    }

    private function showApi(): void {
        $cfg = load_config();
        $kb = inline_kb([
            [btn('🔑 Generate API Key', 'api:gen', 'primary')],
            [btn_url('📄 API Documentation', rtrim($cfg['base_url'], '/') . '/api-docs', 'success')],
            [btn($this->i->t('btn_home'), 'm:home', 'primary')],
        ]);
        $this->showTracked($this->i->t('api_title'), $kb);
    }

    private function showLanguage(): void {
        $st = db()->prepare("SELECT * FROM languages WHERE bot_id=? AND active=1 ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $l) $rows[] = [btn($l['name'] . ' — ' . $l['native_name'], 'lang:' . $l['code'], $this->altStyle($i++))];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $this->showTracked($this->i->t('language_title'), inline_kb($rows));
    }

    private function showSupport(): void {
        $st = db()->prepare("SELECT COUNT(*) c, SUM(status='open') o FROM support_tickets WHERE bot_id=? AND user_id=?");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $r = $st->fetch();
        // Original bot's exact Support Center text (hardcoded in code so
        // pulling alone fixes it; the live DB still holds the old text).
        $txt = "📞 Support Center\n━━━━━━━━━━━━━━━━━━━━\nNeed help? Create a support ticket!\n✍️ Your Tickets: " . (int)$r['c'] . " total\n😮 Open: " . (int)$r['o'] . "\nChoose an option:";
        $kb = inline_kb([
            [btn('🎫 New Ticket', 'tic:new', 'primary'), btn('📋 My Tickets', 'tic:list', 'success')],
            [btn('🔙 Back', 'm:home', 'primary')],
        ]);
        $this->showTracked($txt, $kb);
    }

    private function showHowTo(): void {
        $rows = [];
        $i = 0;
        foreach (howto_topics() as $k => $t) $rows[] = [btn($t['title'], 'how:' . $k, $this->altStyle($i++))];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $this->showTracked($this->i->t('howto_title'), inline_kb($rows));
    }

    // Orders screen: TRUE overall stats (never filtered) plus the filtered
    // order list as clickable buttons. The stats are computed over ALL of
    // the user's orders, so the header never lies when a filter is on.
    // Spent counts only delivered orders — a pending or cancelled order is
    // not money spent.
    // Orders screen. The per-status counts live INSIDE the filter buttons
    // (e.g. "✅ Delivered (3)"), and the order list only appears after the
    // user taps a filter — the first view shows just the stats + filters,
    // never the whole list at once.
    private function ordersScreen(string $filter, int $page, bool $showList): array {
        $pdo = db();
        $stats = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status='delivered' THEN total_usd ELSE 0 END),0) spent, COALESCE(SUM(status='delivered'),0) delivered, COALESCE(SUM(status='pending'),0) pending, COALESCE(SUM(status='refunded'),0) refunded, COALESCE(SUM(status='cancelled'),0) cancelled FROM orders WHERE bot_id=? AND user_id=?");
        $stats->execute([$this->bot['id'], $this->user['id']]);
        $s = $stats->fetch();
        $filterLabel = $filter === 'all' ? 'All' : ucfirst($filter);
        $txt = "🧾 My Orders — {$filterLabel}\n\n"
            . "Total: " . (int)$s['total'] . " orders · $" . fmt_usd($s['spent']) . " spent";
        $kbRows = [];
        if ($showList) {
            $where = "o.bot_id=? AND o.user_id=?";
            $params = [$this->bot['id'], $this->user['id']];
            if ($filter !== 'all') { $where .= " AND o.status=?"; $params[] = $filter; }
            $perPage = 8;
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $where");
            $cnt->execute($params);
            $totalRows = (int)$cnt->fetchColumn();
            $pages = max(1, (int)ceil($totalRows / $perPage));
            if ($page > $pages) $page = $pages;
            $off = ($page - 1) * $perPage;
            $st = $pdo->prepare("SELECT o.*, COALESCE(p.name, 'Product') pname FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE $where ORDER BY o.created_at DESC LIMIT $perPage OFFSET $off");
            $st->execute($params);
            $rows = $st->fetchAll();
            if (!$rows) {
                $txt .= "\n\nNo orders in this filter yet.";
            } else {
                $txt .= "\n\nTap an order to see its details and actions:";
                foreach ($rows as $o) {
                    $label = '#' . $o['id'] . ' · ' . mb_substr((string)$o['pname'], 0, 24) . ' · $' . fmt_usd($o['total_usd']);
                    $kbRows[] = [btn($label, 'o:' . (int)$o['id'], 'primary')];
                }
            }
            $nav = [];
            if ($page > 1) $nav[] = btn('⬅️ Prev', 'ord:' . $filter . ':' . ($page - 1), 'primary');
            if ($page < $pages) $nav[] = btn('Next ➡️', 'ord:' . $filter . ':' . ($page + 1), 'primary');
            if ($nav) $kbRows[] = $nav;
        } else {
            $txt .= "\n\nTap a filter to see its orders.";
        }
        $kbRows[] = [btn('✅ Delivered (' . (int)$s['delivered'] . ')', 'ord:delivered:1', 'primary'), btn('⏳ Pending (' . (int)$s['pending'] . ')', 'ord:pending:1', 'success')];
        $kbRows[] = [btn('↩️ Refunded (' . (int)$s['refunded'] . ')', 'ord:refunded:1', 'primary'), btn('❌ Cancelled (' . (int)$s['cancelled'] . ')', 'ord:cancelled:1', 'success')];
        $kbRows[] = [btn('📋 All (' . (int)$s['total'] . ')', 'ord:all:1', 'primary'), btn($this->i->t('btn_back'), 'm:home', 'primary')];
        return [$txt, inline_kb($kbRows)];
    }

    private function showOrders(string $filter, int $page): void {
        [$txt, $kb] = $this->ordersScreen($filter, $page, false);
        $this->showTracked($txt, $kb);
    }

    // ---------- callbacks ----------
    private function onCallback(array $cb): void {
        $this->chatId = (int)$cb['message']['chat']['id'];
        $this->getUser($cb['from']);
        $data = $cb['data'] ?? '';
        $msgId = (int)$cb['message']['message_id'];
        $parts = explode(':', $data);
        $cmd = $parts[0];

        // Any navigation (Cancel, another item, Home) removes the extra
        // copy message, the same way the tracked menu is cleaned up.
        $this->deleteCopyMsg();

        // Favorites has no list behind it yet: acknowledge with a toast
        // instead of a fake confirmation message (and never double-answer
        // a callback query — Telegram rejects the second answer).
        if ($cmd === 'fav') {
            tg_answer($this->token, $cb['id'], '❤️ Added to favorites.');
            return;
        }
        tg_answer($this->token, $cb['id']);

        // The welcome menu lives on the animated banner (a media message), which
        // has a caption but no text: editMessageText fails on it, so use
        // editMessageCaption for media messages and editMessageText otherwise.
        // The result is checked: a failed edit falls back to a fresh message
        // so the button never silently dies, and tracking follows the
        // message that actually ends up on screen.
        $isMedia = isset($cb['message']['animation']) || isset($cb['message']['photo'])
            || isset($cb['message']['video']) || isset($cb['message']['caption']);
        $edit = function (string $txt, $kb = null) use ($msgId, $isMedia): void {
            $res = $isMedia
                ? tg_edit_caption($this->token, $this->chatId, $msgId, $txt, $kb)
                : tg_edit($this->token, $this->chatId, $msgId, $txt, $kb);
            if ($res['ok'] ?? false) {
                $this->menuTrack((int)($res['result']['message_id'] ?? $msgId), !empty($res['_fallback_text']) ? false : $isMedia);
                return;
            }
            if (str_contains((string)($res['description'] ?? ''), 'message is not modified')) {
                $this->menuTrack($msgId, $isMedia);
                return;
            }
            // Edit failed (message deleted / too old): replace it.
            tg_api($this->token, 'deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $msgId]);
            $r2 = tg_send($this->token, $this->chatId, $txt, $kb);
            if ($r2['ok'] ?? false) $this->menuTrack((int)$r2['result']['message_id'], false);
        };

        switch ($cmd) {
            case 'm':
                $screen = $parts[1] ?? 'home';
                if ($screen === 'home') {
                    if ($isMedia) {
                        // Edit the banner back to the welcome screen in place.
                        // Short caption (issue 38): the chat-list preview shows
                        // the caption, so it must not be the long Account text.
                        $edit('👋 Welcome to ToolsByDcx', $this->mainMenuKb());
                    } else {
                        // Text screen: remove it and bring the banner back.
                        tg_api($this->token, 'deleteMessage', ['chat_id' => $this->chatId, 'message_id' => $msgId]);
                        $this->sendWelcome();
                    }
                    return;
                }
                $this->editScreen($edit, $screen);
                return;
            case 'cat': $this->showCategory($edit, (int)$parts[1], (int)($parts[2] ?? 1)); return;
            case 'p': $this->showProduct($edit, (int)$parts[1]); return;
            case 'buy': $this->showConfirm((int)$parts[1], 1, $edit); return;
            case 'buym':
                $this->setState('await_qty:' . (int)$parts[1]);
                tg_send($this->token, $this->chatId, '🔢 Enter the quantity you want to buy (1-100):');
                return;
            case 'cf': $this->confirmPurchase($edit, (int)$parts[1], (int)$parts[2], $parts[3] ?? 'points'); return;
            case 'pp':
                if (($parts[1] ?? '') === 'custom') {
                    $this->setState('await_custom_points');
                    tg_send($this->token, $this->chatId, '✏️ Enter a custom deposit amount in USD ($1 minimum):');
                } else {
                    $this->showDepositMethods((float)$parts[1], $edit);
                }
                return;
            case 'copy': $this->copyPaymentAddress($edit, $parts[1] ?? '', (int)($parts[2] ?? 0)); return;
            case 'dp': $this->startDeposit($edit, $parts[1], (float)$parts[2]); return;
            case 'ps':
                $refType = ($parts[1] ?? 'o') === 'd' ? 'd' : 'o';
                $refId = (int)($parts[2] ?? 0);
                $table = $refType === 'd' ? 'deposits' : 'orders';
                $column = $refType === 'd' ? 'method' : 'payment_method';
                $refSt = db()->prepare("SELECT {$column} FROM {$table} WHERE id=? AND bot_id=? AND user_id=?");
                $refSt->execute([$refId, $this->bot['id'], $this->user['id']]);
                $refRow = $refSt->fetch();
                $personalDetails = $this->paymentDetails('binance');
                $isPersonal = ($refRow[$column] ?? '') === 'binance' && $this->binancePersonal($personalDetails);
                $this->setState('await_txid:' . $refType . ':' . $refId);
                tg_send($this->token, $this->chatId, $isPersonal
                    ? '📋 Binance Pay complete karne ke baad payment reference ya order ID #' . $refId . ' ke liye bhejo:'
                    : '📋 Please paste your transaction ID (TXID) for #' . $refId . ':');
                return;
            case 'pc': $this->checkPayment($edit, $parts[1] ?? 'o', (int)($parts[2] ?? 0)); return;
            case 'px': $this->confirmCancel($edit, $parts[1] ?? 'o', (int)($parts[2] ?? 0)); return;
            case 'pxc': $this->cancelOrder($edit, $parts[1] ?? 'o', (int)($parts[2] ?? 0)); return;
            case 'ord': $this->showOrdersCb($edit, $parts[1], (int)($parts[2] ?? 1)); return;
            case 'o': $this->showOrderDetail($edit, (int)$parts[1]); return;
            case 'd': $this->showDepositDetail($edit, (int)$parts[1]); return;
            case 'tic':
                $sub = $parts[1] ?? '';
                if ($sub === 'new') {
                    $this->setState('await_support_name', ['tic_msgs' => []]);
                    $r = tg_send($this->token, $this->chatId, "🎫 New Support Ticket\n👤 Step 1/3: Please enter your Name:\nPlease send your full name or nickname so our team can address you properly.", inline_kb([[btn('❌ Cancel', 'tic:cancel', 'danger')]]));
                    if ($r['ok'] ?? false) {
                        $d = $this->stateData();
                        $d['tic_msgs'] = [(int)$r['result']['message_id']];
                        $this->setState('await_support_name', $d);
                    }
                } elseif ($sub === 'prod') {
                    $this->ticketPickProduct($edit, (int)($parts[2] ?? 0));
                } elseif ($sub === 'general') {
                    $this->ticketPickProduct($edit, 0);
                } elseif ($sub === 'page') {
                    $this->showTicketProducts($edit, (int)($parts[2] ?? 1));
                } elseif ($sub === 'search') {
                    $this->setState('await_support_search', $this->stateData());
                    $edit("🔍 Search Product\nType a product name to search:", inline_kb([[btn('❌ Cancel', 'tic:cancel', 'danger')]]));
                } elseif ($sub === 'change') {
                    $this->setState('await_support_product', $this->stateData());
                    $this->showTicketProducts($edit, 1);
                } elseif ($sub === 'cancel') {
                    $d = $this->stateData();
                    foreach (($d['tic_msgs'] ?? []) as $mid) tg_delete($this->token, $this->chatId, (int)$mid);
                    $this->setState('');
                    $this->sendWelcome(true);
                } elseif ($sub === 'view') {
                    $this->showTicketDetail($edit, (int)($parts[2] ?? 0));
                } else { $this->showTickets($edit); }
                return;
            case 'war': $this->showWarrantyOrders($edit); return;
            case 'warc':
                $this->setState('await_warranty:' . (int)$parts[2], ['type' => $parts[1]]);
                tg_send($this->token, $this->chatId, '🛡️ Describe the problem with order #' . (int)$parts[2] . ':');
                return;
            case 'rev':
                $pid = (int)($parts[1] ?? 0);
                if ($pid > 0) $this->showProductReviews($edit, $pid);
                else $this->showReviews($edit);
                return;
            case 'revrate':
                $this->setState('await_review:' . (int)$parts[2], ['rating' => (int)$parts[1]]);
                tg_send($this->token, $this->chatId, '⭐ You rated ' . (int)$parts[1] . '/5. Now write your review text:');
                return;
            case 'free': $this->claimFreebie($edit, (int)$parts[1]); return;
            case 'api':
                if (($parts[1] ?? '') === 'gen') $this->generateApiKey($edit);
                return;
            case 'lang':
                db()->prepare("UPDATE users SET language=? WHERE id=?")->execute([$parts[1], $this->user['id']]);
                $this->i->setLang($parts[1]);
                // Show the welcome screen in the new language.
                $this->sendWelcome();
                return;
            case 'how': $this->showHowTopic($edit, $parts[1]); return;
            case 'pg': $this->showPriceList($edit, $parts[1] ?? 'all', (int)($parts[2] ?? 1)); return;
        }
    }

    private function editScreen(callable $edit, string $screen): void {
        switch ($screen) {
            case 'shop': $this->showCategories($edit); break;
            case 'points': $this->showPointsCb($edit); break;
            case 'price': $this->showPriceList($edit, 'all', 1); break;
            case 'acct': $this->showAccountCb($edit); break;
            case 'orders': $this->showOrdersCb($edit, 'all', 1); break;
            case 'txns': $this->showTxns($edit); break;
            case 'loyal': $this->showLoyalty($edit); break;
            case 'refer': $this->showRefer($edit); break;
            case 'support': $this->showSupportCb($edit); break;
            case 'war': $this->showWarrantyOrders($edit); break;
            case 'rev': $this->showReviews($edit); break;
            case 'lang': $this->showLanguageCb($edit); break;
            case 'howto': $this->showHowToCb($edit); break;
            case 'freebies': $this->showFreebiesCb($edit); break;
            case 'api': $this->showApiCb($edit); break;
        }
    }

    private function showFreebiesCb(callable $edit): void {
        $st = db()->prepare("SELECT * FROM freebies WHERE bot_id=? AND active=1 ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $f) $rows[] = [btn($f['title'], 'free:' . $f['id'], $this->altStyle($i++))];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $edit($this->i->t('freebies_title'), inline_kb($rows));
    }

    private function showApiCb(callable $edit): void {
        $cfg = load_config();
        $kb = inline_kb([
            [btn('🔑 Generate API Key', 'api:gen', 'primary')],
            [btn_url('📄 API Documentation', rtrim($cfg['base_url'], '/') . '/api-docs', 'success')],
            [btn($this->i->t('btn_home'), 'm:home', 'primary')],
        ]);
        $edit($this->i->t('api_title'), $kb);
    }

    private function showHowToCb(callable $edit): void {
        $rows = [];
        $i = 0;
        foreach (howto_topics() as $k => $t) $rows[] = [btn($t['title'], 'how:' . $k, $this->altStyle($i++))];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $edit($this->i->t('howto_title'), inline_kb($rows));
    }

    private function showCategories(callable $edit): void {
        $st = db()->prepare("SELECT * FROM categories WHERE bot_id=? AND active=1 ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        $rows = [];
        $row = [];
        $i = 0;
        foreach ($st->fetchAll() as $c) {
            $row[] = btn($c['emoji'] . ' ' . $c['name'], 'cat:' . $c['id'] . ':1', $this->altStyle($i++));
            if (count($row) === 2) { $rows[] = $row; $row = []; }
        }
        if ($row) $rows[] = $row;
        $rows[] = [btn($this->i->t('shop_classic'), 'pg:all:1', 'primary'), btn($this->i->t('btn_points'), 'm:points', 'success')];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        // Long list: from the banner edit in place (no delete animation);
        // from a text/photo screen send fresh at the bottom (auto-scroll).
        $this->showListScreen($this->i->t('shop_title'), inline_kb($rows), $edit);
    }

    private function showCategory(callable $edit, int $catId, int $page): void {
        $pdo = db();
        $c = $pdo->prepare("SELECT COUNT(*) n FROM products WHERE bot_id=? AND category_id=? AND active=1");
        $c->execute([$this->bot['id'], $catId]);
        $total = (int)$c->fetch()['n'];
        $pages = max(1, (int)ceil($total / 10));
        $page = max(1, min($page, $pages));
        $off = ($page - 1) * 10;
        $st = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') stock
            FROM products p WHERE p.bot_id=? AND p.category_id=? AND p.active=1 ORDER BY p.sort ASC LIMIT 10 OFFSET $off");
        $st->execute([$this->bot['id'], $catId]);
        $cat = $pdo->prepare("SELECT * FROM categories WHERE id=?");
        $cat->execute([$catId]);
        $catRow = $cat->fetch();
        $catName = $catRow['name'] ?? 'Category';
        $catEmoji = $catRow['emoji'] ?? '📁';
        // image_url may not exist yet if the live DB migration
        // (db/add_image_url.sql) has not been run — default to '' so the
        // category still opens instead of the query failing.
        $catImg = trim((string)($catRow['image_url'] ?? ''));
        $txt = "📂 📦 " . $catEmoji . " " . esc($catName) . "\n——————————\n" . ($pages > 1 ? "Page {$page}/{$pages} 🎉\n" : "");
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $p) {
            $stock = (int)$p['stock'];
            $label = ($p['status'] === 'out_of_stock' || $stock === 0)
                ? "❌ {$p['name']} — \${$p['price_usd']}"
                : "{$p['name']} — \${$p['price_usd']} [{$stock} stock]";
            $rows[] = [btn($label, 'p:' . $p['id'], $this->altStyle($i++))];
        }
        $nav = [];
        if ($page > 1) $nav[] = btn('⬅️ Prev', 'cat:' . $catId . ':' . ($page - 1), 'primary');
        if ($page < $pages) $nav[] = btn('Next ➡️', 'cat:' . $catId . ':' . ($page + 1), 'success');
        if ($nav) $rows[] = $nav;
        $rows[] = [btn($this->i->t('btn_back'), 'm:shop', 'primary')];
        // A category with its own image shows that image, not the welcome
        // banner GIF (the banner is only the fallback when no image is set).
        if ($catImg !== '') { $this->showPhotoScreen($catImg, $txt, inline_kb($rows), $edit); return; }
        // Long list: from the banner edit in place (no delete animation);
        // from a text/photo screen send fresh at the bottom (auto-scroll).
        $this->showListScreen($txt, inline_kb($rows), $edit);
    }

    private function product(int $id): ?array {
        $st = db()->prepare("SELECT p.*, (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') stock,
            (SELECT c.image_url FROM categories c WHERE c.id=p.category_id) AS cat_image_url
            FROM products p WHERE p.id=? AND p.bot_id=?");
        $st->execute([$id, $this->bot['id']]);
        return $st->fetch() ?: null;
    }

    private function showProduct(callable $edit, int $pid): void {
        $p = $this->product($pid);
        if (!$p) { $edit('❌ Product not found.', $this->backHomeKb()); return; }
        $stock = (int)$p['stock'];
        $status = ($p['status'] === 'out_of_stock' || $stock === 0) ? '❌ Out of Stock' : '✅ Active';
        $txt = "📦 " . esc($p['name']) . "\n\n" . esc($p['description'] ?: '') .
            "\n\nPrice: \${$p['price_usd']} ≈ Rs. " . pkr_of($this->bot['id'], $p['price_usd']) .
            "\nStatus: {$status}\nDelivery: 🤖 Auto Delivery\nFormat: 🎁 {$p['delivery_format']}" .
            "\nIn Stock: {$stock}\nSold: {$p['sold_count']}";
        $botName = $this->bot['username'] ?: $this->bot['name'];
        $shareUrl = 'https://t.me/share/url?url=' . urlencode('https://t.me/' . $botName)
            . '&text=' . urlencode('📦 ' . $p['name'] . ' — $' . fmt_usd($p['price_usd']) . ' on ' . $this->bot['name'] . '!');
        $kb = inline_kb([
            [btn('❤️ Add to Favorites', 'fav:' . $p['id'], 'success'), btn('🛒 Buy Now', 'buy:' . $p['id'], 'primary')],
            [btn('🔢 Buy Multiple', 'buym:' . $p['id'], 'primary'), btn('⭐ View Reviews', 'rev:' . $p['id'], 'success')],
            [btn_url('📤 Share', $shareUrl, 'success'), btn($this->i->t('btn_back'), 'm:shop', 'primary')],
        ]);
        // A product with its own image shows that image; otherwise it
        // falls back to its category's image, so a product never shows the
        // welcome banner when its category has a logo. Only when neither
        // has an image does the banner stay (the edit-in-place fallback).
        $img = trim((string)($p['image_url'] ?? ''));
        if ($img === '') $img = trim((string)($p['cat_image_url'] ?? ''));
        if ($img !== '') { $this->showPhotoScreen($img, $txt, $kb, $edit); return; }
        $edit($txt, $kb);
    }

    private function showConfirm(int $pid, int $qty, $edit = null): void {
        $p = $this->product($pid);
        if (!$p) {
            $msg = '❌ Product not found.';
            if ($edit) $edit($msg, $this->backHomeKb());
            else $this->showTracked($msg, $this->backHomeKb());
            return;
        }
        $total = (float)$p['price_usd'] * $qty;
        $txt = $this->i->t('confirm_title', [
            'product' => esc($p['name']), 'qty' => $qty,
            'total' => fmt_usd($total), 'pkr' => pkr_of($this->bot['id'], $total),
        ]);
        $labels = [
            'points' => ['💎 Pay with Points (Wallet)', 'primary', true],
            'binance' => ['🟡 Binance Auto Pay', 'success', true],
            'bep20' => ['💰 USDT (BEP20)', 'primary', false],
            'sol' => ['💰 USDT (Solana)', 'success', false],
            'ton' => ['💰 USDT (TON)', 'primary', false],
            'stars' => ['⭐ Telegram Stars', 'success', false],
        ];
        $rows = [];
        $half = [];
        foreach ($this->activePaymentMethods() as $code) {
            if (!isset($labels[$code])) continue;
            [$label, $style, $full] = $labels[$code];
            $b = btn($label, 'cf:' . $pid . ':' . $qty . ':' . $code, $style);
            if ($full) {
                if ($half) { $rows[] = $half; $half = []; }
                $rows[] = [$b];
            } else {
                $half[] = $b;
                if (count($half) === 2) { $rows[] = $half; $half = []; }
            }
        }
        if ($half) $rows[] = $half;
        if (!$rows) {
            $msg = '❌ No payment methods are available right now. Please contact the store admin.';
            if ($edit) $edit($msg, $this->backHomeKb());
            else $this->showTracked($msg, $this->backHomeKb());
            return;
        }
        $rows[] = [btn($this->i->t('btn_back'), 'p:' . $pid, 'primary')];
        $kb = inline_kb($rows);
        if ($edit) $edit($txt, $kb);
        else $this->showTracked($txt, $kb);
    }

    private function confirmPurchase(callable $edit, int $pid, int $qty, string $method): void {
        $p = $this->product($pid);
        if (!$p) { $edit('❌ Product not found.', $this->backHomeKb()); return; }
        if (!$this->paymentMethodActive($method)) { $edit('❌ This payment method is not available right now. Please choose another method.', $this->backHomeKb()); return; }
        $total = (float)$p['price_usd'] * $qty;
        $stock = (int)$p['stock'];
        if ($p['status'] === 'out_of_stock' || $stock < $qty) {
            $edit($this->i->t('no_stock'), $this->backHomeKb()); return;
        }
        if ($method === 'points') {
            $need = points_for($this->bot['id'], $total);
            $have = (float)$this->user['points_wallet'] + (float)$this->user['points_referral'];
            if ($have < $need) { $edit($this->i->t('not_enough_points', ['points' => fmt_points($have)]), $this->backHomeKb()); return; }
            $this->createAndDeliverOrder($p, $qty, $total, 'points', '', $edit);
            return;
        }
        if ($method === 'stars') {
            $this->createStarsInvoice($p, $qty, $total, $edit);
            return;
        }
        if ($method === 'binance') {
            $this->createBinanceOrder($p, $qty, $total, $edit);
            return;
        }
        // Crypto / Binance: pending order + payment screen
        $pdo = db();
        $pdo->prepare("INSERT INTO orders (bot_id, user_id, product_id, qty, unit_price, total_usd, status, payment_method) VALUES (?,?,?,?,?,?, 'pending', ?)")
            ->execute([$this->bot['id'], $this->user['id'], $pid, $qty, $p['price_usd'], $total, $method]);
        $oid = (int)$pdo->lastInsertId();
        $pm = $pdo->prepare("SELECT details FROM payment_methods WHERE bot_id=? AND code=?");
        $pm->execute([$this->bot['id'], $method]);
        $details = json_decode($pm->fetch()['details'] ?? '{}', true) ?: [];
        $addr = $details['address'] ?? $details['uid'] ?? '— set wallet address in Admin → Payment Methods —';
        $net = ['bep20' => 'BEP20', 'sol' => 'Solana', 'ton' => 'TON', 'binance' => 'Binance Pay'][ $method ] ?? $method;
        $txt = $this->i->t('pay_title', ['order_id' => $oid, 'amount' => fmt_usd($total), 'network' => $net, 'address' => $addr]);
        $kb = inline_kb([
            [btn_copy('📋 Copy Payment Address', $addr, 'primary')],
            [btn('✅ Payment Sent', 'ps:o:' . $oid, 'success'), btn('🔍 Check Payment', 'pc:o:' . $oid, 'primary')],
            [btn('❌ Cancel Payment', 'px:o:' . $oid, 'danger')],
        ]);
        $edit($txt, $kb);
    }

    // Deduct points wallet-first, then referral — never let the wallet
    // go negative when the user paid partly with referral points.
    private function deductPoints(float $need): void {
        $wallet = (float)$this->user['points_wallet'];
        $fromWallet = min($wallet, $need);
        $fromRef = $need - $fromWallet;
        if ($fromWallet > 0) add_points($this->bot['id'], $this->user['id'], -$fromWallet, 'order_payment', 'order', null);
        if ($fromRef > 0) add_points($this->bot['id'], $this->user['id'], -$fromRef, 'referral_spent', 'order', null);
    }

    private function createAndDeliverOrder(array $p, int $qty, float $total, string $method, string $txid, callable $edit = null): void {
        $pdo = db();
        $need = points_for($this->bot['id'], $total);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO orders (bot_id, user_id, product_id, qty, unit_price, total_usd, status, payment_method, txid, delivered_at) VALUES (?,?,?,?,?,?, 'delivered', ?, ?, NOW())")
                ->execute([$this->bot['id'], $this->user['id'], $p['id'], $qty, $p['price_usd'], $total, $method, $txid]);
            $oid = (int)$pdo->lastInsertId();
            $st = $pdo->prepare("SELECT id, content FROM stock_items WHERE bot_id=? AND product_id=? AND status='available' ORDER BY id ASC LIMIT $qty FOR UPDATE");
            $st->execute([$this->bot['id'], $p['id']]);
            $items = $st->fetchAll();
            if (count($items) < $qty) throw new Exception('not enough stock');
            // Deduct only now that stock is reserved: a failed delivery
            // rolls the points back too.
            if ($method === 'points') $this->deductPoints($need);
            $contents = [];
            foreach ($items as $it) {
                $pdo->prepare("UPDATE stock_items SET status='sold', sold_order_id=? WHERE id=?")->execute([$oid, $it['id']]);
                $pdo->prepare("INSERT INTO order_items (order_id, stock_item_id, content_snapshot) VALUES (?,?,?)")
                    ->execute([$oid, $it['id'], $it['content']]);
                $contents[] = $it['content'];
            }
            $pdo->prepare("UPDATE products SET sold_count = sold_count + ? WHERE id=?")->execute([$qty, $p['id']]);
            $pdo->prepare("UPDATE users SET total_spent = total_spent + ?, total_orders = total_orders + 1 WHERE id=?")
                ->execute([$total, $this->user['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $msg = '❌ Delivery failed: not enough stock. Please contact support.';
            if ($edit) $edit($msg, $this->backHomeKb()); else tg_send($this->token, $this->chatId, $msg);
            return;
        }
        $this->refreshUser();
        $this->deliver($oid, $p, $qty, $contents, $edit);
    }

    private function deliver(int $oid, array $p, int $qty, array $contents, ?callable $edit): void {
        $title = $this->i->t('delivered_title', ['order_id' => $oid, 'product' => esc($p['name']), 'qty' => $qty]);
        if (count($contents) <= 9) {
            $txt = $title . "\n\n" . implode("\n", $contents);
            if ($edit) $edit($txt, $this->backHomeKb());
            else tg_send($this->token, $this->chatId, $txt, $this->backHomeKb());
        } else {
            $msg = $title . "\n\n📄 Your delivery file is attached (10+ items).";
            if ($edit) $edit($msg, $this->backHomeKb());
            else tg_send($this->token, $this->chatId, $msg, $this->backHomeKb());
            $tmp = sys_get_temp_dir() . "/order_{$oid}.txt";
            file_put_contents($tmp, "Order #{$oid} — {$p['name']} x{$qty}\n\n" . implode("\n", $contents));
            tg_document($this->token, $this->chatId, $tmp);
            @unlink($tmp);
        }
    }

    private function createStarsInvoice(array $p, int $qty, float $total, callable $edit): void {
        $pdo = db();
        $pdo->prepare("INSERT INTO orders (bot_id, user_id, product_id, qty, unit_price, total_usd, status, payment_method) VALUES (?,?,?,?,?,?, 'pending', 'stars')")
            ->execute([$this->bot['id'], $this->user['id'], $p['id'], $qty, $p['price_usd'], $total]);
        $oid = (int)$pdo->lastInsertId();
        $res = tg_api($this->token, 'sendInvoice', [
            'chat_id' => $this->chatId,
            'title' => $p['name'],
            'description' => $p['name'] . ' x' . $qty,
            'payload' => 'order:' . $oid,
            'currency' => 'XTR',
            'prices' => json_encode([['label' => $p['name'], 'amount' => (int)round($total * 100)]]),
        ]);
        if (!($res['ok'] ?? false)) {
            $edit('❌ Telegram Stars payment could not be started. Try another payment method.', $this->backHomeKb());
            db()->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
        }
    }

    private function onPreCheckout(array $q): void {
        tg_api($this->token, 'answerPreCheckoutQuery', ['pre_checkout_query_id' => $q['id'], 'ok' => true]);
    }

    private function onSuccessfulPayment(array $msg): void {
        $this->chatId = (int)$msg['chat']['id'];
        $this->getUser($msg['from']);
        $payload = $msg['successful_payment']['invoice_payload'] ?? '';
        if (str_starts_with($payload, 'deposit:')) {
            $did = (int)substr($payload, 8);
            $st = db()->prepare("SELECT * FROM deposits WHERE id=? AND bot_id=? AND user_id=? AND status='pending'");
            $st->execute([$did, $this->bot['id'], $this->user['id']]);
            $dep = $st->fetch();
            if (!$dep) return;
            db()->prepare("UPDATE deposits SET status='confirmed' WHERE id=?")->execute([$did]);
            add_points($this->bot['id'], $this->user['id'], (float)$dep['points'], 'deposit', 'deposit', $did);
            $this->refreshUser();
            tg_send($this->token, $this->chatId, "✅ Deposit confirmed! +" . fmt_points($dep['points']) . " points (Deposit #{$did}, \$" . fmt_usd($dep['amount_usd']) . "). Your balance is now " . fmt_points((float)$this->user['points_wallet'] + (float)$this->user['points_referral']) . " points.");
            return;
        }
        if (!str_starts_with($payload, 'order:')) return;
        $oid = (int)substr($payload, 6);
        $st = db()->prepare("SELECT o.*, p.name pname FROM orders o JOIN products p ON p.id=o.product_id WHERE o.id=? AND o.bot_id=? AND o.status='pending'");
        $st->execute([$oid, $this->bot['id']]);
        $o = $st->fetch();
        if (!$o) return;
        $p = $this->product((int)$o['product_id']);
        if (!$p) return;
        $this->createAndDeliverOrder($p, (int)$o['qty'], (float)$o['total_usd'], 'stars', $msg['successful_payment']['telegram_payment_charge_id'] ?? '');
    }

    private function startDeposit(callable $edit, string $method, float $usd): void {
        if (!$this->paymentMethodActive($method)) { $edit('❌ This payment method is not available right now. Please choose another method.', $this->backHomeKb()); return; }
        $points = points_for($this->bot['id'], $usd);
        $pdo = db();
        $pdo->prepare("INSERT INTO deposits (bot_id, user_id, amount_usd, points, method, status) VALUES (?,?,?,?,?, 'pending')")
            ->execute([$this->bot['id'], $this->user['id'], $usd, $points, $method]);
        $did = (int)$pdo->lastInsertId();
        if ($method === 'stars') {
            $res = tg_api($this->token, 'sendInvoice', [
                'chat_id' => $this->chatId,
                'title' => 'Buy ' . fmt_points($points) . ' Points',
                'description' => 'Deposit $' . fmt_usd($usd) . ' for ' . fmt_points($points) . ' points',
                'payload' => 'deposit:' . $did,
                'currency' => 'XTR',
                'prices' => json_encode([['label' => 'Points', 'amount' => (int)round($usd * 100)]]),
            ]);
            if (!($res['ok'] ?? false)) {
                $edit('❌ Telegram Stars payment could not be started. Try another payment method.', $this->backHomeKb());
                db()->prepare("UPDATE deposits SET status='failed' WHERE id=?")->execute([$did]);
                return;
            }
            $edit('⭐ Stars invoice sent. Complete the payment in Telegram to receive your points.', $this->backHomeKb());
            return;
        }
        if ($method === 'binance') {
            $tradeNo = $this->binanceTradeNo('d', $did);
            $details = $this->paymentDetails('binance');
            if (!BinancePay::configured($details)) {
                $pdo->prepare("UPDATE deposits SET status='failed' WHERE id=?")->execute([$did]);
                $edit('❌ Binance Pay is not configured yet. Please contact the store admin.', $this->backHomeKb());
                return;
            }
            if ($this->binancePersonal($details)) {
                // Personal account: no API-created link is possible. Show
                // the owner's Binance Pay UID and the exact amount; the
                // customer sends it in the Binance app and the bot verifies
                // it from the Pay history on Check Payment.
                $uid = trim((string)($details['uid'] ?? ''));
                if ($uid === '') {
                    $pdo->prepare("UPDATE deposits SET status='failed' WHERE id=?")->execute([$did]);
                    $edit('❌ Binance Pay is not configured yet. Please contact the store admin.', $this->backHomeKb());
                    return;
                }
                $currency = strtoupper(trim((string)($details['currency'] ?? 'USDT')));
                $txt = "🟡 Binance Pay\n\nDeposit #{$did}\nAmount: $" . fmt_usd($usd) . " {$currency} → " . fmt_points($points) . " points\n\nSend exactly $" . fmt_usd($usd) . " {$currency} to this Binance Pay UID in your Binance app:\n\n<code>{$uid}</code>\n\nAfter sending, tap Check Payment and paste the Order ID Binance showed you.";
                $copyDetails = "Binance Pay UID: {$uid}\nAmount: $" . fmt_usd($usd) . " {$currency}";
                $kb = inline_kb([
                    [btn_copy('📋 Copy UID', $uid, 'primary'), btn_copy('📋 Copy Details', $copyDetails, 'success')],
                    [btn('🔍 Check Payment', 'pc:d:' . $did, 'primary'), btn('❌ Cancel', 'px:d:' . $did, 'danger')],
                ]);
                $edit($txt, $kb);
                return;
            }
            $res = BinancePay::createOrder($details, $tradeNo, $usd, 'Points deposit', 'deposit-' . $did);
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $url = (string)($data['checkoutUrl'] ?? $data['universalUrl'] ?? $data['deeplink'] ?? '');
            if (($res['status'] ?? '') !== 'SUCCESS' || $url === '') {
                error_log('Binance deposit order failed: ' . json_encode(['code' => $res['code'] ?? '', 'message' => $res['errorMessage'] ?? $res['error'] ?? 'unknown']));
                $pdo->prepare("UPDATE deposits SET status='failed' WHERE id=?")->execute([$did]);
                $edit('❌ Binance Pay could not start this deposit. Please try again or choose another method.', $this->backHomeKb());
                return;
            }
            $pdo->prepare("UPDATE deposits SET binance_trade_no=?, binance_prepay_id=? WHERE id=?")
                ->execute([$tradeNo, (string)($data['prepayId'] ?? ''), $did]);
            $txt = "🟡 Binance Pay\n\nDeposit #{$did}\nAmount: $" . fmt_usd($usd) . " USDT → " . fmt_points($points) . " points\n\nOpen Binance Pay, complete the payment, then tap Check Payment. Points are credited automatically after confirmation.";
            $kb = inline_kb([
                [btn_url('🟡 Open Binance Pay', $url, 'success')],
                [btn('🔍 Check Payment', 'pc:d:' . $did, 'primary'), btn('❌ Cancel', 'px:d:' . $did, 'danger')],
            ]);
            $edit($txt, $kb);
            return;
        }
        $pm = $pdo->prepare("SELECT details FROM payment_methods WHERE bot_id=? AND code=?");
        $pm->execute([$this->bot['id'], $method]);
        $details = json_decode($pm->fetch()['details'] ?? '{}', true) ?: [];
        $addr = $details['address'] ?? $details['uid'] ?? '— set wallet address in Admin → Payment Methods —';
        $net = ['bep20' => 'BEP20', 'sol' => 'Solana', 'ton' => 'TON', 'binance' => 'Binance Pay'][$method] ?? $method;
        $txt = "💰 Deposit #{$did}\n\nAmount: \${$usd} (" . fmt_points($points) . " points)\nSend Exactly \${$usd} ({$net}) to:\n{$addr}\n\nYou have 15 minutes. After sending, paste your TXID or tap Payment Sent.";
        $kb = inline_kb([
            [btn('✅ Payment Sent', 'ps:d:' . $did, 'success'), btn('🔍 Check Payment', 'pc:d:' . $did, 'primary')],
            [btn('❌ Cancel', 'px:d:' . $did, 'danger')],
        ]);
        $edit($txt, $kb);
    }

    // Send the payment address as a tappable code block so the user can
    // copy it. $type is 'o' (order) or 'd' (deposit).
    private function copyPaymentAddress(callable $edit, string $type, int $refId): void {
        $pdo = db();
        $method = '';
        if ($type === 'd') {
            $st = $pdo->prepare("SELECT method FROM deposits WHERE id=? AND bot_id=? AND user_id=?");
            $st->execute([$refId, $this->bot['id'], $this->user['id']]);
            $row = $st->fetch();
            $method = $row['method'] ?? '';
        } else {
            $st = $pdo->prepare("SELECT payment_method FROM orders WHERE id=? AND bot_id=? AND user_id=?");
            $st->execute([$refId, $this->bot['id'], $this->user['id']]);
            $row = $st->fetch();
            $method = $row['payment_method'] ?? '';
        }
        if ($method === '') { $edit('❌ Reference not found.', $this->backHomeKb()); return; }
        $pm = $pdo->prepare("SELECT details FROM payment_methods WHERE bot_id=? AND code=?");
        $pm->execute([$this->bot['id'], $method]);
        $details = json_decode($pm->fetch()['details'] ?? '{}', true) ?: [];
        $addr = $details['address'] ?? $details['uid'] ?? '';
        if ($addr === '') { $edit('❌ No wallet address is set for this method yet.', $this->backHomeKb()); return; }
        $res = tg_send($this->token, $this->chatId, "📋 Payment address — tap to copy:\n\n<code>" . esc($addr) . "</code>");
        if ($res['ok'] ?? false) $this->trackCopyMsg((int)$res['result']['message_id']);
    }

    private function checkPayment(callable $edit, string $type, int $refId): void {
        if (($this->user['state'] ?? '') === 'await_binance_order:' . $type . ':' . $refId) $this->setState('');
        $pdo = db();
        $table = $type === 'd' ? 'deposits' : 'orders';
        $st = $pdo->prepare("SELECT * FROM $table WHERE id=? AND bot_id=? AND user_id=?");
        $st->execute([$refId, $this->bot['id'], $this->user['id']]);
        $o = $st->fetch();
        if (!$o) { $edit('❌ Payment reference not found.', $this->backHomeKb()); return; }
        $details = $this->paymentDetails('binance');
        // Personal account: verification is ONLY by the Binance Order ID
        // the sender pastes — no automatic amount match, and the exact
        // amount is not checked either (owner's decision). The bot checks
        // that exact Order ID against the Pay history and confirms only
        // when the owner's UID, the currency and the Order ID all match
        // (findPaymentByOrderId). There is no
        // API-created pay link in personal mode (the customer sends to the
        // owner's UID), so this must not require binance_pay_link.
        if (($o['method'] ?? $o['payment_method'] ?? '') === 'binance'
            && $this->binancePersonal($details)) {
            $this->setState('await_binance_order:' . $type . ':' . $refId);
            $edit('🔍 Paste your Binance Order ID below 👇', $this->backHomeKb());
            return;
        }
        if (($o['method'] ?? $o['payment_method'] ?? '') === 'binance' && (string)$o['binance_trade_no'] !== '') {
            if (!BinancePay::configured($details)) {
                $edit('❌ Binance Pay is not configured yet. Please contact the store admin.', $this->backHomeKb());
                return;
            }
            $res = BinancePay::queryOrder($details, (string)$o['binance_trade_no'], (string)$o['binance_prepay_id']);
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            if (($res['status'] ?? '') !== 'SUCCESS' || !$data) {
                error_log('Binance query failed: ' . json_encode(['code' => $res['code'] ?? '', 'message' => $res['errorMessage'] ?? $res['error'] ?? 'unknown']));
                $edit('⏳ Binance Pay is temporarily unavailable. Please tap Check Payment again in a moment.', $this->checkPaymentKb($type, $refId));
                return;
            }
            $status = (string)($data['status'] ?? '');
            if ($status === 'PAID') {
                $amount = (float)($data['totalFee'] ?? -1);
                $currency = strtoupper((string)($data['currency'] ?? ''));
                if ($currency !== 'USDT' || abs($amount - (float)$o[$type === 'd' ? 'amount_usd' : 'total_usd']) > 0.00000001) {
                    error_log('Binance payment amount mismatch: ' . json_encode(['id' => $refId, 'amount' => $amount, 'currency' => $currency]));
                    $edit('❌ Binance reported a payment, but the amount or currency does not match this reference. Contact support before paying again.', $this->backHomeKb());
                    return;
                }
                $txid = (string)($data['transactionId'] ?? $data['prepayId'] ?? '');
                if ($type === 'd') {
                    $this->confirmBinanceDeposit($refId, $txid);
                } else {
                    $p = $this->product((int)$o['product_id']);
                    if (!$p) { $edit('❌ Product no longer exists. Contact support with this order number.', $this->backHomeKb()); return; }
                    $this->fulfillExistingOrder($refId, $p, (int)$o['qty'], $txid, $edit);
                }
                return;
            }
            if (in_array($status, ['EXPIRED', 'CANCELED', 'ERROR'], true)) {
                $edit('❌ Binance payment status: ' . $status . '. You can start a new payment from the product screen.', $this->backHomeKb());
                return;
            }
            $edit('⏳ Binance payment status: ' . ($status ?: 'PENDING') . '. Complete the payment, then check again.', $this->checkPaymentKb($type, $refId));
            return;
        }
        if ($o['txid'] !== '') {
            $edit("⏳ Payment for #{$refId} is under admin review (TXID: " . esc($o['txid']) . "). You will be notified once confirmed.", $this->backHomeKb());
            return;
        }
        $this->setState('await_txid:' . $type . ':' . $refId);
        $edit("📋 No TXID recorded yet for #{$refId}. Please paste your transaction ID (TXID) here and an admin will verify it.", $this->backHomeKb());
    }

    private function fulfillExistingOrder(int $oid, array $p, int $qty, string $txid, ?callable $edit = null): bool {
        $pdo = db();
        $contents = [];
        $alreadyDelivered = false;
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM orders WHERE id=? AND bot_id=? AND user_id=? FOR UPDATE");
            $st->execute([$oid, $this->bot['id'], $this->user['id']]);
            $order = $st->fetch();
            if (!$order) throw new Exception('order not found');
            if ($order['status'] === 'delivered') { $alreadyDelivered = true; $pdo->commit(); }
            elseif ($order['status'] !== 'pending' && !($order['status'] === 'cancelled' && $order['payment_method'] === 'binance')) {
                $pdo->commit(); return false;
            } else {
                $stock = $pdo->prepare("SELECT id, content FROM stock_items WHERE bot_id=? AND product_id=? AND status='available' ORDER BY id ASC LIMIT $qty FOR UPDATE");
                $stock->execute([$this->bot['id'], $p['id']]);
                $items = $stock->fetchAll();
                if (count($items) < $qty) throw new Exception('not enough stock');
                foreach ($items as $it) {
                    $pdo->prepare("UPDATE stock_items SET status='sold', sold_order_id=? WHERE id=?")->execute([$oid, $it['id']]);
                    $pdo->prepare("INSERT INTO order_items (order_id, stock_item_id, content_snapshot) VALUES (?,?,?)")
                        ->execute([$oid, $it['id'], $it['content']]);
                    $contents[] = $it['content'];
                }
                $pdo->prepare("UPDATE orders SET status='delivered', txid=?, delivered_at=NOW() WHERE id=? AND status IN ('pending','cancelled')")
                    ->execute([$txid, $oid]);
                $pdo->prepare("UPDATE products SET sold_count = sold_count + ? WHERE id=?")->execute([$qty, $p['id']]);
                $pdo->prepare("UPDATE users SET total_spent = total_spent + ?, total_orders = total_orders + 1 WHERE id=?")
                    ->execute([$order['total_usd'], $this->user['id']]);
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Keep the payment trace so an admin can reconcile a paid order
            // when stock is temporarily exhausted.
            $pdo->prepare("UPDATE orders SET txid=? WHERE id=? AND bot_id=? AND payment_method='binance'")
                ->execute([$txid, $oid, $this->bot['id']]);
            error_log('Binance order fulfillment failed #' . $oid . ': ' . $e->getMessage());
            if ($edit) $edit('✅ Payment received, but delivery is waiting for stock. Contact support with order #' . $oid . '.', $this->backHomeKb());
            else tg_send($this->token, $this->chatId, '✅ Payment received for order #' . $oid . ', but delivery is waiting for stock. Contact support.');
            return false;
        }
        if ($alreadyDelivered) {
            if ($edit) $edit('✅ This payment was already confirmed and the order was delivered.', $this->backHomeKb());
            return true;
        }
        $this->refreshUser();
        $this->deliver($oid, $p, $qty, $contents, $edit);
        return true;
    }

    private function confirmBinanceDeposit(int $did, string $txid): bool {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND bot_id=? AND user_id=? FOR UPDATE");
            $st->execute([$did, $this->bot['id'], $this->user['id']]);
            $dep = $st->fetch();
            if (!$dep) throw new Exception('deposit not found');
            if ($dep['status'] === 'confirmed') { $pdo->commit(); return true; }
            if (!in_array($dep['status'], ['pending', 'cancelled'], true)) { $pdo->commit(); return false; }
            $pdo->prepare("UPDATE deposits SET status='confirmed', txid=? WHERE id=?")->execute([$txid, $did]);
            add_points($this->bot['id'], $this->user['id'], (float)$dep['points'], 'deposit', 'deposit', $did);
            $pdo->commit();
            $this->refreshUser();
            tg_send($this->token, $this->chatId, "✅ Binance payment confirmed! +" . fmt_points($dep['points']) . " points (Deposit #{$did}, $" . fmt_usd($dep['amount_usd']) . "). Your balance is now " . fmt_points((float)$this->user['points_wallet'] + (float)$this->user['points_referral']) . " points.", $this->backHomeKb());
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Binance deposit fulfillment failed #' . $did . ': ' . $e->getMessage());
            return false;
        }
    }

    // Called by the Binance webhook after its RSA signature and amount have
    // been validated. It is idempotent: duplicate notifications cannot double
    // deliver stock or credit points.
    public function handleBinanceWebhook(array $data): bool {
        $tradeNo = trim((string)($data['merchantTradeNo'] ?? ''));
        if ($tradeNo === '' || strtoupper((string)($data['currency'] ?? '')) !== 'USDT') return false;
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM orders WHERE bot_id=? AND binance_trade_no=? LIMIT 1");
        $st->execute([$this->bot['id'], $tradeNo]);
        $order = $st->fetch();
        $type = 'o';
        $row = $order;
        if (!$row) {
            $st = $pdo->prepare("SELECT * FROM deposits WHERE bot_id=? AND binance_trade_no=? LIMIT 1");
            $st->execute([$this->bot['id'], $tradeNo]);
            $row = $st->fetch();
            $type = 'd';
        }
        if (!$row) return true; // Old/other merchant notification; acknowledge it.
        $expected = (float)($type === 'd' ? $row['amount_usd'] : $row['total_usd']);
        if (abs((float)($data['totalFee'] ?? -1) - $expected) > 0.00000001) return false;
        $u = $pdo->prepare("SELECT * FROM users WHERE id=? AND bot_id=?");
        $u->execute([(int)$row['user_id'], $this->bot['id']]);
        $this->user = $u->fetch() ?: null;
        if (!$this->user) return false;
        $this->i->setLang($this->user['language'] ?? 'en');
        $this->chatId = (int)$this->user['telegram_id'];
        if ($type === 'd') return $this->confirmBinanceDeposit((int)$row['id'], (string)($data['transactionId'] ?? ''));
        $p = $this->product((int)$row['product_id']);
        if (!$p) return false;
        return $this->fulfillExistingOrder((int)$row['id'], $p, (int)$row['qty'], (string)($data['transactionId'] ?? ''));
    }

    // Cancelling is destructive (the order leaves Pending for good), so
    // ask first. Tapping "Cancel Payment" by mistake used to silently move
    // the order to Cancelled, which looked like the pending order had
    // vanished from the Pending filter.
    private function confirmCancel(callable $edit, string $type, int $oid): void {
        $label = $type === 'd' ? "deposit #{$oid}" : "order #{$oid}";
        $back = $type === 'd' ? 'm:home' : 'o:' . $oid;
        $kb = inline_kb([
            [btn('✅ Yes, cancel it', 'pxc:' . $type . ':' . $oid, 'danger')],
            [btn('⬅️ No, keep it', $back, 'primary')],
        ]);
        $edit("⚠️ Cancel {$label}?\n\nThe payment will be cancelled and you will need to start again if you still want it.", $kb);
    }

    private function cancelOrder(callable $edit, string $type, int $oid): void {
        $pdo = db();
        if ($type === 'd') {
            $pdo->prepare("UPDATE deposits SET status='cancelled' WHERE id=? AND bot_id=? AND user_id=? AND status='pending'")
                ->execute([$oid, $this->bot['id'], $this->user['id']]);
        } else {
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND bot_id=? AND user_id=? AND status='pending'")
                ->execute([$oid, $this->bot['id'], $this->user['id']]);
        }
        $edit("❌ #{$oid} cancelled.", $this->backHomeKb());
    }

    private function showPointsCb(callable $edit): void {
        $points = (float)$this->user['points_wallet'] + (float)$this->user['points_referral'];
        $rate = setting($this->bot['id'], 'points_rate', 10);
        $txt = $this->i->t('points_title', ['points' => fmt_points($points), 'rate' => $rate]);
        $kb = inline_kb([
            [btn('$1', 'pp:1', 'primary'), btn('$5', 'pp:5', 'success'), btn('$10', 'pp:10', 'primary'), btn('$25', 'pp:25', 'success')],
            [btn('✏️ Custom Amount', 'pp:custom', 'primary')],
            [btn($this->i->t('btn_back'), 'm:home', 'primary')],
        ]);
        $edit($txt, $kb);
    }

    private function showAccountCb(callable $edit): void {
        $u = $this->user;
        $points = (float)$u['points_wallet'] + (float)$u['points_referral'];
        $st = db()->prepare("SELECT COUNT(*) c FROM referrals WHERE bot_id=? AND referrer_id=? AND status='approved'");
        $st->execute([$this->bot['id'], $u['id']]);
        $refs = (int)$st->fetch()['c'];
        $txt = $this->i->t('account_title', [
            'name' => esc($u['first_name']), 'username' => esc($u['username']) ?: '—',
            'user_id' => $u['telegram_id'], 'joined' => substr($u['joined_at'], 0, 10),
            'points' => fmt_points($points), 'wallet' => fmt_points($u['points_wallet']),
            'referral' => fmt_points($u['points_referral']),
            'balance' => fmt_usd($points / (float)setting($this->bot['id'], 'points_rate', 10)),
            'referrals' => $refs, 'status' => 'Active',
        ]);
        $edit($txt, $this->backHomeKb());
    }

    private function showPriceList(callable $edit, string $filter, int $page): void {
        $pdo = db();
        $where = "bot_id=? AND active=1";
        $params = [$this->bot['id']];
        if ($filter === 'available') { $where .= " AND status='active'"; }
        if ($filter === 'out') { $where .= " AND status='out_of_stock'"; }
        $c = $pdo->prepare("SELECT COUNT(*) n FROM products WHERE $where");
        $c->execute($params);
        $total = (int)$c->fetch()['n'];
        $pages = max(1, (int)ceil($total / 10));
        $page = max(1, min($page, $pages));
        $off = ($page - 1) * 10;
        $st = $pdo->prepare("SELECT * FROM products WHERE $where ORDER BY name ASC LIMIT 10 OFFSET $off");
        $st->execute($params);
        $txt = $this->i->t('price_title', ['count' => $total]) . "\n\n";
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $p) {
            $mark = $p['status'] === 'out_of_stock' ? '❌' : '✅';
            $txt .= "{$mark} " . esc($p['name']) . " — \${$p['price_usd']}\n";
            $rows[] = [btn($p['name'] . ' — $' . $p['price_usd'], 'p:' . $p['id'], $this->altStyle($i++))];
        }
        $nav = [
            btn('✅ Available', 'pg:available:1', 'success'),
            btn('❌ Out of Stock', 'pg:out:1', 'danger'),
            btn('📋 All', 'pg:all:1', 'primary'),
        ];
        $rows[] = $nav;
        $pg = [];
        if ($page > 1) $pg[] = btn('⬅️ Prev', 'pg:' . $filter . ':' . ($page - 1), 'primary');
        if ($page < $pages) $pg[] = btn('Next ➡️', 'pg:' . $filter . ':' . ($page + 1), 'success');
        if ($pg) $rows[] = $pg;
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        // Long list: from the banner edit in place (no delete animation);
        // from a text/photo screen send fresh at the bottom (auto-scroll).
        $this->showListScreen($txt, inline_kb($rows), $edit);
    }

    private function showOrdersCb(callable $edit, string $filter, int $page): void {
        [$txt, $kb] = $this->ordersScreen($filter, $page, true);
        $edit($txt, $kb);
    }

    private function showOrderDetail(callable $edit, int $oid): void {
        $st = db()->prepare("SELECT o.*, p.name pname FROM orders o JOIN products p ON p.id=o.product_id WHERE o.id=? AND o.bot_id=? AND o.user_id=?");
        $st->execute([$oid, $this->bot['id'], $this->user['id']]);
        $o = $st->fetch();
        if (!$o) { $edit('❌ Order not found.', $this->backHomeKb()); return; }
        $status = (string)$o['status'];
        $method = (string)($o['payment_method'] ?? $o['method'] ?? '');
        $txt = "🧾 Order #{$o['id']}\n\nProduct: " . esc($o['pname']) . "\nQty: {$o['qty']}\nTotal: \${$o['total_usd']}\nStatus: {$status}\nPayment: {$method}\nDate: {$o['created_at']}";
        if (!empty($o['txid'])) $txt .= "\nTXID: <code>" . esc($o['txid']) . "</code>";
        $kbRows = [];
        if ($status === 'pending') {
            // Payment actions follow the order's own payment method, so the
            // user can finish or check exactly the payment they started.
            // Every pending order can be cancelled — nobody is ever stuck
            // on a pending order with no way out.
            if ($method === 'binance') {
                $details = $this->paymentDetails('binance');
                $uid = trim((string)($details['uid'] ?? ''));
                $currency = strtoupper(trim((string)($details['currency'] ?? 'USDT')));
                if ($uid !== '') $txt .= "\n\nSend \$" . fmt_usd($o['total_usd']) . " {$currency} to Binance Pay UID:\n<code>{$uid}</code>";
                $kbRows[] = [btn('🔍 Check Payment', 'pc:o:' . $o['id'], 'primary'), btn('❌ Cancel Payment', 'px:o:' . $o['id'], 'danger')];
            } elseif (in_array($method, ['bep20', 'sol', 'ton'], true)) {
                $details = $this->paymentDetails($method);
                $addr = trim((string)($details['address'] ?? ''));
                if ($addr !== '') $txt .= "\n\nSend \$" . fmt_usd($o['total_usd']) . " USDT (" . strtoupper($method) . ") to:\n<code>{$addr}</code>";
                $kbRows[] = [btn('🔍 Check Payment', 'pc:o:' . $o['id'], 'primary'), btn('❌ Cancel Payment', 'px:o:' . $o['id'], 'danger')];
            } elseif ($method === 'stars') {
                $txt .= "\n\nComplete the Telegram Stars invoice in this chat to pay, or cancel below.";
                $kbRows[] = [btn('❌ Cancel Payment', 'px:o:' . $o['id'], 'danger')];
            } else {
                $txt .= "\n\nYour payment is being processed. You will be notified once it is confirmed.";
                $kbRows[] = [btn('❌ Cancel Payment', 'px:o:' . $o['id'], 'danger')];
            }
            if (!empty($o['txid'])) {
                $txt .= "\n\n⏳ Your payment reference was received. Waiting for admin approval.";
            }
        } elseif ($status === 'delivered') {
            $txt .= "\n\nYour product was delivered in this chat. If it stopped working, open a warranty claim or ask for a refund below.";
            $kbRows[] = [btn('🛡️ Warranty Claim', 'warc:warranty:' . $o['id'], 'primary'), btn('↩️ Refund Request', 'warc:refund:' . $o['id'], 'danger')];
        }
        $kbRows[] = [btn($this->i->t('btn_back'), 'ord:all:1', 'primary')];
        $edit($txt, inline_kb($kbRows));
    }

    private function showTxns(callable $edit): void {
        $st = db()->prepare("SELECT * FROM deposits WHERE bot_id=? AND user_id=? ORDER BY created_at DESC LIMIT 10");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $rows = $st->fetchAll();
        if (!$rows) { $edit($this->i->t('txns_empty'), $this->backHomeKb()); return; }
        $txt = "🔄 Transactions\n";
        $kbRows = [];
        $statusEmoji = ['pending' => '⏳', 'confirmed' => '✅', 'cancelled' => '❌', 'failed' => '⚠️'];
        foreach ($rows as $d) {
            $pts = (float)$d['points'];
            $ptsStr = ($pts == (int)$pts) ? (string)(int)$pts : fmt_points($pts);
            $st = (string)$d['status'];
            $line = ($statusEmoji[$st] ?? '•') . " #{$d['id']}  \$" . fmt_usd($d['amount_usd']) . "  {$ptsStr} pts  " . ucfirst($st);
            if ($d['status'] === 'pending') {
                // A pending deposit is tappable: it opens the deposit
                // detail with Check Payment + Cancel, so it never sits
                // stuck with no way to pay or cancel it.
                $txt .= "\n" . $line;
                $kbRows[] = [btn("💎 Deposit #{$d['id']}  \$" . fmt_usd($d['amount_usd']) . "  Pay / Cancel", 'd:' . (int)$d['id'], 'primary')];
            } else {
                $txt .= "\n" . $line;
            }
        }
        $txt .= "\n\nTap a pending deposit to pay or cancel it.";
        $kbRows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $edit($txt, inline_kb($kbRows));
    }

    // Deposit detail: same Pay + Cancel workflow as order detail, so a
    // pending deposit is never stuck. Confirmed/failed/cancelled deposits
    // show no actions, just Back.
    private function showDepositDetail(callable $edit, int $did): void {
        $st = db()->prepare("SELECT * FROM deposits WHERE id=? AND bot_id=? AND user_id=?");
        $st->execute([$did, $this->bot['id'], $this->user['id']]);
        $d = $st->fetch();
        if (!$d) { $edit('❌ Deposit not found.', $this->backHomeKb()); return; }
        $status = (string)$d['status'];
        $method = (string)$d['method'];
        $txt = "💎 Deposit #{$d['id']}\n\nAmount: \${$d['amount_usd']}\nPoints: " . fmt_points($d['points']) . "\nStatus: {$status}\nPayment: {$method}\nDate: {$d['created_at']}";
        if (!empty($d['txid'])) $txt .= "\nTXID: <code>" . esc($d['txid']) . "</code>";
        $kbRows = [];
        if ($status === 'pending') {
            if ($method === 'binance') {
                $details = $this->paymentDetails('binance');
                $uid = trim((string)($details['uid'] ?? ''));
                $currency = strtoupper(trim((string)($details['currency'] ?? 'USDT')));
                if ($uid !== '') $txt .= "\n\nSend \$" . fmt_usd($d['amount_usd']) . " {$currency} to Binance Pay UID:\n<code>{$uid}</code>";
                $kbRows[] = [btn('🔍 Check Payment', 'pc:d:' . $d['id'], 'primary'), btn('❌ Cancel Payment', 'px:d:' . $d['id'], 'danger')];
            } elseif (in_array($method, ['bep20', 'sol', 'ton'], true)) {
                $details = $this->paymentDetails($method);
                $addr = trim((string)($details['address'] ?? ''));
                if ($addr !== '') $txt .= "\n\nSend \$" . fmt_usd($d['amount_usd']) . " USDT (" . strtoupper($method) . ") to:\n<code>{$addr}</code>";
                $kbRows[] = [btn('🔍 Check Payment', 'pc:d:' . $d['id'], 'primary'), btn('❌ Cancel Payment', 'px:d:' . $d['id'], 'danger')];
            } elseif ($method === 'stars') {
                $txt .= "\n\nComplete the Telegram Stars invoice in this chat to pay, or cancel below.";
                $kbRows[] = [btn('❌ Cancel Payment', 'px:d:' . $d['id'], 'danger')];
            } else {
                $txt .= "\n\nYour payment is being processed. You will be notified once it is confirmed.";
                $kbRows[] = [btn('❌ Cancel Payment', 'px:d:' . $d['id'], 'danger')];
            }
            if (!empty($d['txid'])) {
                $txt .= "\n\n⏳ Your payment reference was received. Waiting for admin approval.";
            }
        }
        $kbRows[] = [btn($this->i->t('btn_back'), 'm:txns', 'primary')];
        $edit($txt, inline_kb($kbRows));
    }

    private function showLoyalty(callable $edit): void {
        $u = $this->user;
        $t = loyalty_tier_for($this->bot['id'], (float)$u['total_spent'], (int)$u['total_orders']);
        $cur = $t['current']; $next = $t['next'];
        $progress = $next
            ? 'Next: ' . $next['name'] . ' at $' . fmt_usd($next['min_spent']) . ' / ' . $next['min_orders'] . ' orders (' . $next['discount_pct'] . '% off)'
            : 'Top tier reached! 🎉';
        $txt = $this->i->t('loyalty_title', [
            'tier' => $cur['name'], 'spent' => fmt_usd($u['total_spent']),
            'orders' => $u['total_orders'], 'progress' => $progress,
        ]);
        $st = db()->prepare("SELECT * FROM loyalty_tiers WHERE bot_id=? ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        foreach ($st->fetchAll() as $tier) {
            $txt .= "\n{$tier['name']}: \${$tier['min_spent']}+ / {$tier['min_orders']}+ orders — {$tier['discount_pct']}%";
        }
        $edit($txt, $this->backHomeKb());
    }

    private function showRefer(callable $edit): void {
        $botName = $this->bot['username'] ?: $this->bot['name'];
        $link = 'https://t.me/' . $botName . '?start=' . $this->user['telegram_id'];
        $st = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(points_awarded),0) e FROM referrals WHERE bot_id=? AND referrer_id=? AND status='approved'");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $r = $st->fetch();
        $txt = $this->i->t('refer_title', [
            'link' => $link, 'count' => (int)$r['c'], 'earned' => fmt_points($r['e']),
            'reward' => setting($this->bot['id'], 'referral_reward', 0.1),
            'milestone' => setting($this->bot['id'], 'referral_milestone', 20),
            'milestone_reward' => setting($this->bot['id'], 'referral_milestone_reward', 10),
        ]);
        $kb = inline_kb([
            [btn_url('📤 Share My Link', 'https://t.me/share/url?url=' . urlencode($link) . '&text=' . urlencode('Join ' . $this->bot['name'] . ' and earn rewards!'), 'success')],
            [btn($this->i->t('btn_back'), 'm:home', 'primary')],
        ]);
        $edit($txt, $kb);
    }

    private function showSupportCb(callable $edit): void {
        $st = db()->prepare("SELECT COUNT(*) c, SUM(status='open') o FROM support_tickets WHERE bot_id=? AND user_id=?");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $r = $st->fetch();
        // Original bot's exact Support Center text (see showSupport).
        $txt = "📞 Support Center\n━━━━━━━━━━━━━━━━━━━━\nNeed help? Create a support ticket!\n✍️ Your Tickets: " . (int)$r['c'] . " total\n😮 Open: " . (int)$r['o'] . "\nChoose an option:";
        $kb = inline_kb([
            [btn('🎫 New Ticket', 'tic:new', 'primary'), btn('📋 My Tickets', 'tic:list', 'success')],
            [btn('🔙 Back', 'm:home', 'primary')],
        ]);
        $edit($txt, $kb);
    }

    private function showTickets(callable $edit): void {
        $st = db()->prepare("SELECT * FROM support_tickets WHERE bot_id=? AND user_id=? ORDER BY created_at DESC LIMIT 10");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $rows = $st->fetchAll();
        // Original bot's exact My Tickets format: each ticket is a
        // clickable button that opens its detail view.
        $txt = "📋 Your Tickets\n━━━━━━━━━━━━━━━━━━━━";
        $kbRows = [];
        $i = 0;
        foreach ($rows as $t) {
            $mark = $t['status'] === 'open' ? '🟡 Open' : '✅ Closed';
            $txt .= "\n🎫 #{$t['id']} {$mark}\n📝 " . esc($t['subject']) . "\n📅 " . date('d M h:i A', strtotime($t['created_at']));
            $kbRows[] = [btn("🎫 #{$t['id']} — " . $t['subject'] . " ( {$mark} )", 'tic:view:' . (int)$t['id'], $this->altStyle($i++))];
        }
        if (!$rows) $txt .= "\n\nYou have no tickets yet.";
        $kbRows[] = [btn('🎫 New Ticket', 'tic:new', 'primary')];
        $kbRows[] = [btn('🔙 Back', 'm:home', 'primary')];
        $edit($txt, inline_kb($kbRows));
    }

    // Ticket detail view (original bot): subject, status, created date and
    // the full "Your Message" content. No reply button — the original has
    // none; a follow-up typed in the chat does not attach to the ticket,
    // and only the admin can close it.
    private function showTicketDetail(callable $edit, int $tid): void {
        $st = db()->prepare("SELECT * FROM support_tickets WHERE id=? AND bot_id=? AND user_id=?");
        $st->execute([$tid, $this->bot['id'], $this->user['id']]);
        $t = $st->fetch();
        if (!$t) { $this->showTickets($edit); return; }
        $m = db()->prepare("SELECT message FROM ticket_messages WHERE ticket_id=? AND sender='user' ORDER BY created_at ASC LIMIT 1");
        $m->execute([$tid]);
        $msg = $m->fetch()['message'] ?? '';
        $mark = $t['status'] === 'open' ? '🟡 Open' : '✅ Resolved';
        $txt = "🎫 Ticket #{$t['id']}\n━━━━━━━━━━━━━━━━━━━━\n📝 Subject: " . esc($t['subject']) . "\n📊 Status: {$mark}\n📅 Created: " . date('d M Y h:i A', strtotime($t['created_at'])) . "\n📄 Your Message:\n" . esc($msg);
        $edit($txt, inline_kb([
            [btn('📋 My Tickets', 'tic:list', 'primary')],
            [btn('🔙 Back', 'm:home', 'primary')],
        ]));
    }

    // ---------- ticket product picker (original bot method) ----------
    // Step 2/3 of a support ticket: pick the product the ticket is about.
    // 8 active products per page, paginated, plus General / Other Inquiry.
    // Returns [text, keyboard] so it can be sent as a new message or used
    // to edit the picker message in place.
    private function ticketPicker(int $page, string $name, string $query = ''): array {
        $pdo = db();
        $where = "bot_id=? AND active=1 AND status='active'";
        $params = [$this->bot['id']];
        if ($query !== '') { $where .= " AND name LIKE ?"; $params[] = '%' . $query . '%'; }
        $c = $pdo->prepare("SELECT COUNT(*) n FROM products WHERE $where");
        $c->execute($params);
        $total = (int)$c->fetch()['n'];
        $pages = max(1, (int)ceil($total / 8));
        $page = max(1, min($page, $pages));
        $off = ($page - 1) * 8;
        $st = $pdo->prepare("SELECT id, name, price_usd FROM products WHERE $where ORDER BY name ASC LIMIT 8 OFFSET $off");
        $st->execute($params);
        $txt = "🎫 New Support Ticket\nStep 2/3 — 👤 Name: " . esc($name) . "\n📦 Which product do you need help with? Tap a product button below or use Search / Next / Prev:";
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $p) {
            $rows[] = [btn($p['name'] . ' — $' . $p['price_usd'], 'tic:prod:' . (int)$p['id'], $this->altStyle($i++))];
        }
        if (!$rows) $txt .= "\n\nNo products match your search.";
        if ($pages > 1) $txt .= "\n\nPage {$page} of {$pages} · {$total} products";
        $nav = [btn('🔍 Search Product', 'tic:search', 'primary')];
        if ($page > 1) $nav[] = btn('⬅️ Prev', 'tic:page:' . ($page - 1), 'primary');
        if ($page < $pages) $nav[] = btn('Next ➡️', 'tic:page:' . ($page + 1), 'success');
        $rows[] = $nav;
        $rows[] = [btn('❓ General / Other Inquiry', 'tic:general', 'success')];
        $rows[] = [btn('❌ Cancel', 'tic:cancel', 'danger')];
        return [$txt, inline_kb($rows)];
    }

    // Render the picker by editing the callback's message in place.
    // Preserves an active search query so paging a search keeps filtering.
    private function showTicketProducts(callable $edit, int $page, string $query = ''): void {
        $d = $this->stateData();
        if ($query === '') $query = $d['query'] ?? '';
        [$txt, $kb] = $this->ticketPicker($page, $d['name'] ?? '', $query);
        $edit($txt, $kb);
    }

    // User picked a product (or General). Move to Step 3/3 by editing the
    // picker message in place into the issue prompt.
    private function ticketPickProduct(callable $edit, int $productId): void {
        $d = $this->stateData();
        $name = $d['name'] ?? '';
        if ($productId > 0) {
            $st = db()->prepare("SELECT name FROM products WHERE id=? AND bot_id=?");
            $st->execute([$productId, $this->bot['id']]);
            $product = $st->fetch()['name'] ?? 'General / Account Inquiry';
        } else {
            $product = 'General / Account Inquiry';
        }
        $d['product'] = $product;
        $this->setState('await_support_msg', $d);
        $edit("🎫 New Support Ticket\n👤 Name: " . esc($name) . "\n📦 Product: " . esc($product) . "\n📝 Step 3/3: Please describe your issue in detail:\nExplain what went wrong or how we can assist you. Type your message below and send it:", inline_kb([
            [btn('🔙 Change Product', 'tic:change', 'primary')],
            [btn('❌ Cancel', 'tic:cancel', 'danger')],
        ]));
    }

    private function showWarrantyOrders(callable $edit): void {
        $st = db()->prepare("SELECT o.id, p.name pname, o.status FROM orders o JOIN products p ON p.id=o.product_id WHERE o.bot_id=? AND o.user_id=? AND o.status='delivered' ORDER BY o.created_at DESC LIMIT 10");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $rows = $st->fetchAll();
        if (!$rows) { $edit($this->i->t('warranty_title') . "\n\nNo delivered orders yet.", $this->backHomeKb()); return; }
        $kbRows = [];
        $i = 0;
        foreach ($rows as $o) $kbRows[] = [btn("#{$o['id']} — {$o['pname']}", 'o:' . $o['id'], $this->altStyle($i++))];
        $kbRows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $edit($this->i->t('warranty_title'), inline_kb($kbRows));
    }

    private function showReviews(callable $edit): void {
        $pdo = db();
        $st = $pdo->prepare("SELECT COUNT(*) c FROM reviews WHERE bot_id=? AND user_id=?");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $mine = (int)$st->fetch()['c'];
        $st = $pdo->prepare("SELECT COUNT(*) c FROM orders o WHERE o.bot_id=? AND o.user_id=? AND o.status='delivered'
            AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.product_id=o.product_id AND r.user_id=o.user_id)");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $pending = (int)$st->fetch()['c'];
        $txt = $this->i->t('reviews_title', ['mine' => $mine, 'pending' => $pending]);
        $kbRows = [];
        $st = $pdo->prepare("SELECT DISTINCT p.id, p.name FROM orders o JOIN products p ON p.id=o.product_id
            WHERE o.bot_id=? AND o.user_id=? AND o.status='delivered'
            AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.product_id=o.product_id AND r.user_id=o.user_id) LIMIT 5");
        $st->execute([$this->bot['id'], $this->user['id']]);
        $i = 0;
        foreach ($st->fetchAll() as $p) $kbRows[] = [btn('⭐ Review: ' . $p['name'], 'revrate:5:' . $p['id'], $this->altStyle($i++))];
        $kbRows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $edit($txt, inline_kb($kbRows));
    }

    // Reviews of one product (from its detail page), approved only.
    private function showProductReviews(callable $edit, int $pid): void {
        $p = $this->product($pid);
        if (!$p) { $edit('❌ Product not found.', $this->backHomeKb()); return; }
        $st = db()->prepare("SELECT r.rating, r.text, u.first_name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.bot_id=? AND r.product_id=? AND r.status='approved' ORDER BY r.created_at DESC LIMIT 5");
        $st->execute([$this->bot['id'], $pid]);
        $rows = $st->fetchAll();
        $txt = "⭐ Reviews — " . esc($p['name']) . "\n";
        if (!$rows) $txt .= "\nNo reviews yet.";
        foreach ($rows as $r) {
            $txt .= "\n\n" . str_repeat('⭐', max(1, min(5, (int)$r['rating']))) . ' ' . esc($r['first_name']) . "\n" . esc($r['text'] ?? '');
        }
        $edit($txt, inline_kb([[btn($this->i->t('btn_back'), 'p:' . $pid, 'primary')]]));
    }

    private function showLanguageCb(callable $edit): void {
        $st = db()->prepare("SELECT * FROM languages WHERE bot_id=? AND active=1 ORDER BY sort ASC");
        $st->execute([$this->bot['id']]);
        $rows = [];
        $i = 0;
        foreach ($st->fetchAll() as $l) $rows[] = [btn($l['name'] . ' — ' . $l['native_name'], 'lang:' . $l['code'], $this->altStyle($i++))];
        $rows[] = [btn($this->i->t('btn_back'), 'm:home', 'primary')];
        $edit($this->i->t('language_title'), inline_kb($rows));
    }

    private function showHowTopic(callable $edit, string $key): void {
        $topics = howto_topics();
        if (!isset($topics[$key])) { $edit('❌ Topic not found.', $this->backHomeKb()); return; }
        $body = $this->i->t('howto_' . $key);
        if ($body === 'howto_' . $key) $body = $topics[$key]['body'];
        $txt = $this->i->t('howto_body', ['topic' => $topics[$key]['title'], 'body' => $body]);
        $edit($txt, inline_kb([[btn($this->i->t('btn_back'), 'm:howto', 'primary')]]));
    }

    private function claimFreebie(callable $edit, int $fid): void {
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM freebies WHERE id=? AND bot_id=? AND active=1");
        $st->execute([$fid, $this->bot['id']]);
        $f = $st->fetch();
        if (!$f) { $edit('❌ Freebie not found.', $this->backHomeKb()); return; }
        $st = $pdo->prepare("SELECT 1 FROM freebie_claims WHERE bot_id=? AND freebie_id=? AND user_id=?");
        $st->execute([$this->bot['id'], $fid, $this->user['id']]);
        if ($st->fetch()) { $edit('✅ You already claimed this freebie.', $this->backHomeKb()); return; }
        if ($f['claim_limit'] > 0 && $f['claims_count'] >= $f['claim_limit']) {
            $edit('❌ This freebie has reached its claim limit.', $this->backHomeKb()); return;
        }
        $pdo->prepare("INSERT INTO freebie_claims (bot_id, freebie_id, user_id) VALUES (?,?,?)")
            ->execute([$this->bot['id'], $fid, $this->user['id']]);
        $pdo->prepare("UPDATE freebies SET claims_count = claims_count + 1 WHERE id=?")->execute([$fid]);
        $txt = "🎁 " . esc($f['title']) . "\n\n" . ($f['delivery_text'] ?: '') .
            "\n\n🧾 Invoice: FREE — Order #F{$fid}-{$this->user['id']}";
        $edit($txt, $this->backHomeKb());
    }

    private function generateApiKey(callable $edit): void {
        $key = 'tdcx_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $key);
        db()->prepare("INSERT INTO reseller_api_keys (bot_id, user_id, api_key_hash, last4) VALUES (?,?,?,?)")
            ->execute([$this->bot['id'], $this->user['id'], $hash, substr($key, -4)]);
        $cfg = load_config();
        $txt = "🔑 Your Reseller API Key (shown ONLY once — save it now):\n\n<code>{$key}</code>\n\nDocs: " . rtrim($cfg['base_url'], '/') . "/api-docs\n\nYour key is linked to your wallet balance.";
        $edit($txt, $this->backHomeKb());
    }
}
