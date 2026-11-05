# ToolsByDcx Telegram Store — Build Contract

Multi-bot Telegram store (PHP 8.3 + MariaDB, webhook-based). Clone of @Bite_storee_bot
per `~/workspace/telegram_clone/bite_store_analysis.md`. Bot name for the new
bot: **ToolsByDcx**.

## Layout (repo root = deploy root `~/domains/telegram.toolsbydcx.com/`)
- `config.php` — NOT in repo (see `config.example.php`). Lives OUTSIDE `public_html`.
- `public/` → deployed as `public_html/`
  - `public/bot/webhook.php` — Telegram webhook entry. URL: `https://telegram.toolsbydcx.com/bot/webhook.php?b=<bot_id>&s=<webhook_secret>`
  - `public/admin/` — admin panel (login, dashboard, bots, catalogue, orders, users, tickets, settings…)
  - `public/api/index.php` — reseller API (`/api/...` via rewrite or direct path)
  - `public/api-docs/` — HTML API docs
  - `public/assets/banner.png` — welcome banner
- `lib/` — `config.php` (loader), `Database.php`, `Telegram.php`, `I18n.php`, `helpers.php`, `Bot.php` (handler logic)
- `db/schema.sql`, `db/seed.sql`, `db/migrate.php` (runs schema+seed, idempotent-ish)
- `tools/test_harness.php` — CLI: feeds simulated Telegram updates to the handler, prints resulting API calls
- `setup.php` — one-time installer at deploy root (creates first admin, then must be deleted/disabled)

## Config (`config.php`, outside web root)
```php
return [
  'db_host' => 'localhost',
  'db_name' => 'u390461415_telegram',
  'db_user' => 'u390461415_telegram',
  'db_pass' => '***',
  'app_key' => 'random-32-char-string', // encrypts bot tokens at rest
  'base_url' => 'https://telegram.toolsbydcx.com',
];
```

## Money rules (from analysis, stored in bot_settings, never hardcoded in logic)
- Points rate: $1 = 10 points (setting `points_rate`, default 10).
- PKR display: price_usd * `pkr_rate` (default 300), shown as ≈ Rs. X.
- Loyalty tiers (seeded, editable): Bronze $0+/0+ 0%; Silver $20+/3+ 5%;
  Gold $100+/10+ 10%; Platinum $300+/25+ 15%; Diamond $1000+/50+ 20%.
- Referral: +0.1 point to BOTH sides on approval (settings `referral_reward`);
  milestone 20 refs = +10 pts (`referral_milestone`, `referral_milestone_reward`).
  Force-join channel (`force_join_channel`, empty = off) + math verification question.

## Callback data (max 64 bytes, colon-separated, short codes)
- `m:shop` `m:points` `m:price` `m:acct` `m:orders` `m:txns` `m:loyal` `m:refer`
  `m:support` `m:warranty` `m:reviews` `m:lang`
- `cat:<id>:<page>` product list, `p:<id>` product detail
- `buy:<id>` buy 1, `buym:<id>` buy multiple (ask qty), `fav:<id>`, `share:<id>`
- `cf:<id>:<qty>:<method>` confirm purchase (method: points|binance|bep20|sol|ton|stars)
- `pay:points:<amt>` buy-points packs, `pay:custom` custom amount
- `ps:<order_id>` payment sent, `pc:<order_id>` check payment, `px:<order_id>` cancel
- `ord:<filter>:<page>` orders list, `o:<id>` order detail
- `tic:new` `tic:list` `tic:<id>` tickets; `war:<order_id>` warranty; `rev:new:<product_id>`
- `free:<id>` freebie claim; `api:gen` generate key; `lang:<code>`
- `pg:price:<filter>:<page>` price list pagination

## User state machine (`users.state`, `users.state_data` JSON)
States: none, await_qty:<product_id>, await_custom_points, await_support_name,
await_support_msg, await_math:<a>+<b>, await_txid:<order_id>, await_deposit_txid.
Reply-keyboard text commands are matched by translated button text AND English fallback.

## Telegram.php API surface
`tg($token, $method, array $params)` → decoded array. Helpers:
`sendMessage($token,$chatId,$text,$replyMarkup=null,$parseMode='HTML')`,
`editMessage($token,$chatId,$messageId,$text,$replyMarkup=null)`,
`answerCallback($token,$cbId,$text=null)`, `sendPhoto(...)`, `sendDocument(...)`.
All bot texts go through `I18n::t($botId,$lang,$key,$vars)` with English fallback.

## Admin panel
Session login, `admin_users` (password_hash). Pages: dashboard, bots (add by token,
panel sets webhook via setWebhook + getMe to fill username, test connection),
categories, products, stock, freebies, loyalty, languages/texts, payment_methods,
orders, users, deposits, referrals, tickets, warranty, reviews, api_keys,
broadcasts, settings. Everything CRUD; all shop content from DB.

## Reseller API
Header `X-API-Key: <key>`. Endpoints: `GET /api/products`, `GET /api/categories`,
`GET /api/stock?product_id=`, `POST /api/order` {product_id, qty},
`GET /api/order/<id>`. Charges the reseller's wallet points balance. Docs at `/api-docs`.
