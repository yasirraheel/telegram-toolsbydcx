<?php
// Admin login page (standalone; no sidebar). Included by index.php when logged out.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post_str('username');
    $password = (string)($_POST['password'] ?? '');
    $st = $pdo->prepare("SELECT * FROM admin_users WHERE username=?");
    $st->execute([$username]);
    $admin = $st->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        redirect('index.php?page=dashboard');
    }
    flash('Invalid username or password.', 'err');
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — ToolsByDcx Admin</title>
<style>
body{margin:0;background:#f4f5f7;color:#1f2937;font:15px/1.5 system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;width:340px;box-shadow:0 4px 16px rgba(0,0,0,.06)}
h1{margin:0 0 4px;font-size:20px}.mut{color:#6b7280;font-size:13px;margin:0 0 16px}
label{display:block;font-size:12px;color:#6b7280;margin:12px 0 4px;font-weight:600}
input{width:100%;background:#fff;border:1px solid #d1d5db;color:#1f2937;border-radius:8px;padding:9px 10px;font:inherit;box-sizing:border-box}
input:focus{outline:2px solid #fff1e8;border-color:#ff6622}
button{width:100%;margin-top:18px;background:#ff6622;color:#fff;border:0;border-radius:8px;padding:10px;font:inherit;cursor:pointer;font-weight:600}
button:hover{background:#e6551a}
.flash{padding:10px;border-radius:8px;background:#fee2e2;color:#991b1b;margin-bottom:10px;font-weight:600}
</style></head><body>
<div class="box"><h1>🛒 Tools<span style="color:#ff6622">ByDcx</span> Admin</h1><p class="mut">Sign in to manage your Telegram store bots.</p>
<?php foreach (take_flash() as $f): ?><div class="flash"><?= esc($f['msg']) ?></div><?php endforeach; ?>
<form method="post" action="index.php?page=login">
<label>Username</label><input type="text" name="username" required autofocus>
<label>Password</label><input type="password" name="password" required>
<button type="submit">Log in</button>
</form></div></body></html>
