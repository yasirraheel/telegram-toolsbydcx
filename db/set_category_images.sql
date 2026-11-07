-- Category images: each category's brand logo (Google Favicon, 128px PNG).
-- Run in phpMyAdmin SQL (database u390461415_telegram) AFTER db/add_image_url.sql
-- (the image_url column must exist first). Safe to re-run: it only sets image_url.
-- Any image can be replaced later from Admin > Categories > Image URL.
USE u390461415_telegram;
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=chatgpt.com&sz=128' WHERE id=1 AND bot_id=1; -- Chatgpt
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=grok.com&sz=128' WHERE id=2 AND bot_id=1; -- Grok
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=capcut.com&sz=128' WHERE id=3 AND bot_id=1; -- Capcut
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=freepik.com&sz=128' WHERE id=4 AND bot_id=1; -- Freepik
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=elevenlabs.io&sz=128' WHERE id=5 AND bot_id=1; -- Eleven Labs
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=netflix.com&sz=128' WHERE id=6 AND bot_id=1; -- Netflix
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=claude.ai&sz=128' WHERE id=7 AND bot_id=1; -- Claude
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=cursor.com&sz=128' WHERE id=8 AND bot_id=1; -- Cursor Pro
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=google.com&sz=128' WHERE id=9 AND bot_id=1; -- Gemini
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=gmail.com&sz=128' WHERE id=10 AND bot_id=1; -- Gmails (Numbers)
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=microsoft.com&sz=128' WHERE id=11 AND bot_id=1; -- Microsoft 365 Office
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=adobe.com&sz=128' WHERE id=12 AND bot_id=1; -- Adobe Subscriptions
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=gmail.com&sz=128' WHERE id=13 AND bot_id=1; -- Mail Accounts
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=nordvpn.com&sz=128' WHERE id=14 AND bot_id=1; -- Vpns
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=autodesk.com&sz=128' WHERE id=15 AND bot_id=1; -- Auto Desk
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=primevideo.com&sz=128' WHERE id=16 AND bot_id=1; -- Prime Video
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=canva.com&sz=128' WHERE id=17 AND bot_id=1; -- Canva Pro
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=notion.so&sz=128' WHERE id=18 AND bot_id=1; -- Notion AI
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=ilovepdf.com&sz=128' WHERE id=19 AND bot_id=1; -- ILovePdf Premium
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=grammarly.com&sz=128' WHERE id=20 AND bot_id=1; -- Grammarly Pro
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=linkedin.com&sz=128' WHERE id=21 AND bot_id=1; -- Linkedin
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=figma.com&sz=128' WHERE id=22 AND bot_id=1; -- Figma
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=microsoft.com&sz=128' WHERE id=23 AND bot_id=1; -- Windows Activation Keys
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=max.com&sz=128' WHERE id=24 AND bot_id=1; -- HBO MAX
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=peacocktv.com&sz=128' WHERE id=25 AND bot_id=1; -- Peacock
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=klingai.com&sz=128' WHERE id=26 AND bot_id=1; -- Kling Ai
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=leonardo.ai&sz=128' WHERE id=27 AND bot_id=1; -- Leonardo Ai
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=miro.com&sz=128' WHERE id=28 AND bot_id=1; -- Miro
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=jetbrains.com&sz=128' WHERE id=29 AND bot_id=1; -- JetBrains Edu Pack
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=genspark.ai&sz=128' WHERE id=30 AND bot_id=1; -- Genspark
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=scribd.com&sz=128' WHERE id=31 AND bot_id=1; -- Scribd Premium
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=spotify.com&sz=128' WHERE id=32 AND bot_id=1; -- Spotify
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=bytedance.com&sz=128' WHERE id=33 AND bot_id=1; -- Seedance
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=duolingo.com&sz=128' WHERE id=34 AND bot_id=1; -- Duolingo
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=framer.com&sz=128' WHERE id=35 AND bot_id=1; -- Framer
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=kaspersky.com&sz=128' WHERE id=36 AND bot_id=1; -- Kaspersky
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=railway.app&sz=128' WHERE id=37 AND bot_id=1; -- Railway
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=lovable.dev&sz=128' WHERE id=38 AND bot_id=1; -- Lovable
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=replit.com&sz=128' WHERE id=39 AND bot_id=1; -- Replit
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=n8n.io&sz=128' WHERE id=40 AND bot_id=1; -- N8N Starter
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=gamma.app&sz=128' WHERE id=41 AND bot_id=1; -- Gamma Ai
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=akool.com&sz=128' WHERE id=42 AND bot_id=1; -- Akool
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=telegram.org&sz=128' WHERE id=43 AND bot_id=1; -- 500+ International Resellers Groups
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=telegram.org&sz=128' WHERE id=44 AND bot_id=1; -- Telegram Accounts
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=envato.com&sz=128' WHERE id=45 AND bot_id=1; -- Envato Elements
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=coursera.org&sz=128' WHERE id=46 AND bot_id=1; -- Coursera
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=meitu.com&sz=128' WHERE id=47 AND bot_id=1; -- Wink
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=minimax.com&sz=128' WHERE id=48 AND bot_id=1; -- Minimax
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=quizlet.com&sz=128' WHERE id=49 AND bot_id=1; -- Quizlet
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=meitu.com&sz=128' WHERE id=50 AND bot_id=1; -- Meitu
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=crunchyroll.com&sz=128' WHERE id=51 AND bot_id=1; -- Crunchy Roll
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=heygen.com&sz=128' WHERE id=52 AND bot_id=1; -- Heygen Ai
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=udemy.com&sz=128' WHERE id=53 AND bot_id=1; -- Udemy
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=youtube.com&sz=128' WHERE id=54 AND bot_id=1; -- YouTube
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=meta.com&sz=128' WHERE id=55 AND bot_id=1; -- Muse AI
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=suno.com&sz=128' WHERE id=56 AND bot_id=1; -- Suno
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=zoom.us&sz=128' WHERE id=57 AND bot_id=1; -- Zoom
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=manus.im&sz=128' WHERE id=58 AND bot_id=1; -- Manus prp
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=apple.com&sz=128' WHERE id=59 AND bot_id=1; -- Apple Tv
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=discord.com&sz=128' WHERE id=60 AND bot_id=1; -- Discord
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=kahoot.com&sz=128' WHERE id=61 AND bot_id=1; -- Kahoot
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=meitu.com&sz=128' WHERE id=62 AND bot_id=1; -- Xingtu
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=deepseek.com&sz=128' WHERE id=63 AND bot_id=1; -- Deepseek
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=higgsfield.ai&sz=128' WHERE id=64 AND bot_id=1; -- Higgsfield
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=hide.me&sz=128' WHERE id=65 AND bot_id=1; -- Proxy
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=deezer.com&sz=128' WHERE id=66 AND bot_id=1; -- Deezer
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=jetbrains.com&sz=128' WHERE id=67 AND bot_id=1; -- Jet Brain
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=brain.fm&sz=128' WHERE id=68 AND bot_id=1; -- Brain Fm
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=galaxyplay.vn&sz=128' WHERE id=69 AND bot_id=1; -- Galaxy Play
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=telegram.org&sz=128' WHERE id=70 AND bot_id=1; -- Others
UPDATE categories SET image_url='https://www.google.com/s2/favicons?domain=scribd.com&sz=128' WHERE id=71 AND bot_id=1; -- Scribd
