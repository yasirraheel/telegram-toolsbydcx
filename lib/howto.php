<?php
// How to Use guide — 22 topics (defaults; override any body via the texts
// table in the admin panel with key howto_<topic>).
function howto_topics(): array {
    return [
        'buy_product' => ['title' => 'How to Buy a Product', 'body' => "1. Tap Shop Now and pick a category.\n2. Choose a product and tap Buy Now (1 item) or Buy Multiple (typed quantity).\n3. Confirm the purchase and pick a payment method.\n4. Pay exactly the amount shown. Delivery is automatic."],
        'buy_points' => ['title' => 'How to Buy Points / Deposit', 'body' => "1. Tap Buy Points.\n2. Pick \$1, \$5, \$10, \$25 or a Custom Amount.\n3. Pay with your chosen method.\n4. Points are credited at \$1 = 10 Points exactly (no rounding)."],
        'payments' => ['title' => 'Payment Methods (Overview)', 'body' => "We accept: Points Wallet, Binance Auto Pay, USDT (BEP20 / Solana / TON), Telegram Stars, Bybit Pay and local guides for EasyPaisa and JazzCash. Crypto payments need the exact amount and your TXID."],
        'stars' => ['title' => 'Telegram Stars', 'body' => "Telegram Stars payments are instant. Choose Telegram Stars at checkout and confirm the invoice inside Telegram."],
        'binance' => ['title' => 'Binance USDT', 'body' => "Pay USDT via Binance. Paste your TXID after sending; the payment is auto-verified by API where available, otherwise an admin confirms it."],
        'bybit' => ['title' => 'Bybit Pay / USDT', 'body' => "Pay USDT to the bot Bybit UID shown at checkout, then tap to verify. Bybit USDT is also accepted on TRC20 and BEP20."],
        'easypaisa' => ['title' => 'EasyPaisa', 'body' => "Follow the EasyPaisa guide shown at checkout and send the exact PKR amount. Share the transaction ID with support if asked."],
        'jazzcash' => ['title' => 'JazzCash', 'body' => "Follow the JazzCash guide shown at checkout and send the exact PKR amount. Share the transaction ID with support if asked."],
        'pay_points' => ['title' => 'Pay with Points', 'body' => "Points are your wallet balance. \$1 = 10 Points exactly. Buy points first, then pay with points at checkout for instant delivery."],
        'ticket' => ['title' => 'How to Create a Support Ticket', 'body' => "Support → New Ticket. Step 1: your full name or nickname. Step 2: subject. Step 3: describe the issue. We reply within 24-48 hours."],
        'warranty' => ['title' => 'How to Claim Warranty', 'body' => "Warranty → pick a delivered order → Warranty Claim. Describe the problem. Claims are admin-reviewed within 24-48 hours."],
        'replacement' => ['title' => 'How to Request Replacement', 'body' => "Open a warranty claim for the order and choose replacement. If the product is defective within the warranty period, we replace it or refund based on unused value."],
        'review' => ['title' => 'How to Leave a Review', 'body' => "Reviews → pick a delivered product → rate it 1-5 stars and write your review. Reviews are moderated before they appear."],
        'referrals' => ['title' => 'Free Account / Referrals', 'body' => "Refer → share your link. They join and verify → you BOTH get +0.1 point. 20 referrals = +10 points bonus. No self-referrals."],
        'freebies' => ['title' => 'Freebies', 'body' => "Freebies → pick a free product → Claim FREE Now. The delivery link or method is sent to you right away."],
        'reseller_api' => ['title' => 'Reseller API', 'body' => "Reseller API → Generate API Key (shown only once). Use the key to list products, check stock and place orders from your own bot or website. See /api-docs."],
        'loyalty' => ['title' => 'Tier System & Loyalty', 'body' => "Bronze \$0+/0+ (0%), Silver \$20+/3+ (5%), Gold \$100+/10+ (10%), Platinum \$300+/25+ (15%), Diamond \$1000+/50+ (20%). Your tier discount applies automatically."],
        'orders' => ['title' => 'Order History & Tracking', 'body' => "Orders → filter by Delivered, Pending, Refunded, Cancelled or All. Tap an order for details, warranty or refund."],
        'price_list' => ['title' => 'Price List & Filters', 'body' => "Price List shows all products with filters: Available, Out of Stock, All. Prices are in USD with an approximate PKR value."],
        'language' => ['title' => 'Language Settings', 'body' => "Language → pick from 10 languages. Menus switch immediately; anything untranslated falls back to English."],
        'faq' => ['title' => 'Common Issues / FAQ', 'body' => "Payment not confirmed? Check you sent the exact amount and submitted the TXID. Item not delivered? Check Orders, then open a support ticket. Out of stock? The product will return when restocked."],
        'main_menu' => ['title' => 'Main Menu', 'body' => "Tap Menu anytime to return to the welcome screen with all 12 options: Shop, Points, Price List, Account, Orders, Txns, Loyalty, Refer, Support, Warranty, Reviews and Language."],
    ];
}
