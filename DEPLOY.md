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

## Binance Pay setup
### Personal Pay API
1. Create the personal API Key and Secret in Binance App → Profile → API
   Management. Enable payment permission only, disable trading/withdrawal, and
   whitelist the server IP where possible.
2. In Binance App → Profile → Payment → Agent Pay Settings, complete MFA and
   set the single-transaction and daily limits.
3. In Admin → **Payment Methods** → **Binance Pay API**, select **Personal Pay**,
   enter the API Key and Secret, choose the receive currency (normally USDT),
   and save.
4. The bot creates a fixed-amount personal receive link for each order/deposit.
   Personal Pay has no Merchant webhook/status lookup in this integration, so
   the buyer submits a payment reference and the admin confirms it from Orders
   or Deposits before delivery/crediting points.

### Merchant Pay API (fully automatic)
1. Apply for a **Binance Pay Merchant** account and complete the required KYC/KYB.
2. In the Binance Merchant Portal, open **Developer → API** and create an API
   identity key/API secret. Keep the secret private and whitelist the server IP
   if Binance asks for an IP allowlist.
3. In Admin → **Payment Methods** → **Binance Pay API**, select **Merchant Pay**,
   save the credentials, then click **Fetch webhook certificate from Binance**.
4. In Binance Merchant Portal → **Developer → Webhooks**, register:
   `https://telegram.toolsbydcx.com/binance/webhook.php`
5. Test with a small real payment. The bot creates a hosted Binance checkout,
   verifies the signed `PAY_SUCCESS` notification, and uses Query Order as the
   user-facing fallback. Products/points are fulfilled idempotently.

## Notes
- Webhook-based bot: no long-running process needed on shared hosting.
- Merchant Binance Pay checkout is API-verified when merchant credentials and
  its webhook certificate are configured. Personal Pay links remain admin-
  verified because they do not use the Merchant webhook flow.
- Sample stock items are marked `is_sample=1` — replace them with real delivery
  items in Admin → Stock before going live.
