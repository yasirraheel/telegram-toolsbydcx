# Deploy — ToolsByDcx Telegram Store

## Layout on the server (Hostinger, `user-server` SSH alias)
- Deploy root: `~/domains/telegram.toolsbydcx.com/`
- Web root: `~/domains/telegram.toolsbydcx.com/public_html/` (= repo `public/`)
- Code outside web root: `lib/`, `db/`, `tools/` at deploy root
- Config: `~/domains/telegram.toolsbydcx.com/config.php` (OUTSIDE public_html,
  600 perms, created once from `config.example.php` — never committed)

## First deploy
```bash
# from your local machine / VM (repo has an `origin` remote)
rsync -avz --exclude .git --exclude config.php \
  ~/workspace/clone_telegram_tools/ user-server:~/domains/telegram.toolsbydcx.com/
# then on the server, move public/ into place:
ssh user-server 'cp -r ~/domains/telegram.toolsbydcx.com/public/* ~/domains/telegram.toolsbydcx.com/public_html/'
# run migrations + seed (idempotent):
ssh user-server 'cd ~/domains/telegram.toolsbydcx.com && php db/migrate.php'
# create the first admin (once only), then DELETE setup.php:
ssh user-server 'cd ~/domains/telegram.toolsbydcx.com && php setup.php admin <password> && rm setup.php'
```
Alternative update path: clone the repo once into the deploy root and
`git pull` there, then copy `public/*` to `public_html/` again.

## Verify
- `curl -I https://telegram.toolsbydcx.com/` → 200
- `https://telegram.toolsbydcx.com/admin/` → login page
- Test the bot logic without Telegram: `php tools/test_harness.php`

## Add a bot (no token in code, ever)
1. Create the bot with @BotFather on Telegram → copy the Bot API token.
2. Log in to `https://telegram.toolsbydcx.com/admin/` → **Bots** → **Add Bot**,
   paste the token + a name. The panel calls `getMe` (fills the username) and
   `setWebhook` to `https://telegram.toolsbydcx.com/bot/webhook.php?b=<id>&s=<secret>`.
3. The bot immediately serves from the same codebase under its own `bot_id` scope.
4. Seed the new bot's catalogue with **Copy seed catalogue** (copies categories,
   products, freebies, tiers, languages, texts, payment methods from bot 1),
   or build its catalogue from scratch in the panel.
5. Set its payment wallets in **Payment Methods** (USDT addresses) and configure
   Binance Pay API credentials in the Binance Pay section…
   and its welcome text / banner in **Settings**. No webhook is set until a token
   is added in the panel.

## Binance Pay automatic checkout
1. Apply for a **Binance Pay Merchant** account and complete the required KYC/KYB.
2. In the Binance Merchant Portal, open **Developer → API** and create an API
   identity key/API secret. Keep the secret private and whitelist the server IP
   if Binance asks for an IP allowlist.
3. In this app's Admin → **Payment Methods**, save the API identity key and
   secret, then click **Fetch webhook certificate from Binance**.
4. In Binance Merchant Portal → **Developer → Webhooks**, register:
   `https://telegram.toolsbydcx.com/binance/webhook.php`
5. Test with a small real payment. The bot creates a hosted Binance checkout,
   verifies the signed `PAY_SUCCESS` notification, and uses Query Order as the
   user-facing fallback. Products/points are fulfilled idempotently.

## Notes
- Webhook-based bot: no long-running process needed on shared hosting.
- Binance Pay checkout is API-verified when merchant credentials and its
  webhook certificate are configured. BEP20/Solana/TON remain manual TXID
  methods and still require admin confirmation.
- Sample stock items are marked `is_sample=1` — replace them with real delivery
  items in Admin → Stock before going live.
