<?php
// Admin panel router. Session login required for every page except login.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/layout.php';

$pages = ['dashboard','bots','categories','products','stock','orders','users','deposits',
    'tickets','warranty','reviews','freebies','loyalty','languages','texts','payments',
    'apikeys','broadcasts','settings','referrals','notifications'];
$page = (string)($_GET['page'] ?? 'dashboard');

if ($page === 'logout') {
    session_destroy();
    redirect('index.php?page=login');
}

if (!admin_logged_in()) {
    require __DIR__ . '/login.php';
    exit;
}

if ($page === 'login') redirect('index.php?page=dashboard');
if (!in_array($page, $pages, true)) $page = 'dashboard';

$bots = admin_bots($pdo);
$botId = current_bot_id($pdo);
$bot = $botId ? bot_by_id($botId) : null;

require __DIR__ . '/pages/' . $page . '.php';
