# ToolsByDcx — Telegram Store Bot + Admin Panel

A complete clone of the @Bite_storee_bot store (see
`~/workspace/telegram_clone/bite_store_analysis.md`), rebuilt as **ToolsByDcx**:
a multi-bot Telegram store with a fully dynamic admin panel.

## Stack
PHP 8.3 + MariaDB, webhook-based Telegram bot (no long-running process),
plain PHP admin panel, reseller API.

## Structure
- `public/` → web root (`public_html` on the server)
  - `bot/webhook.php` — Telegram webhook entry (`?b=<bot_id>&s=<secret>`)
  - `admin/` — admin panel (login, dashboard, multi-bot, catalogue, orders…)
  - `api/` — reseller API (`X-API-Key` auth)
  - `api-docs/` — API documentation page
  - `assets/banner.png` — welcome banner
- `lib/` — Database, Telegram client, I18n, helpers, Bot handler, How-to guide
- `db/` — `schema.sql`, `seed.sql`, `seed_stock.php`, `migrate.php`
- `tools/` — `test_harness.php` (simulated Telegram updates), `make_banner.py`
- `config.example.php` — copy to `config.php` (outside web root, never committed)
- `CONTRACT.md` — build contract; `DEPLOY.md` — deployment guide

## Key rules
- **No hardcoded shop content.** Categories, products, prices, stock, texts,
  payment wallets, tiers and freebies all live in the database and are managed
  from the admin panel.
- **No secrets in the repo.** `config.php` (DB password, app key) lives only on
  the server, outside `public_html`.
- **Multi-bot:** add any bot by pasting its Bot API token in the panel; the
  panel sets the webhook and the bot serves from the same codebase under its
  own `bot_id` scope.
- **Binance Pay:** Admin → Payment Methods supports Personal Pay receive links
  (admin verification) and Merchant Pay hosted checkout (webhook/API
  verification). Secrets are encrypted in the database. See `DEPLOY.md`.

## Quick start
See `DEPLOY.md`. Test bot logic locally (on the server) with:
`php tools/test_harness.php`
