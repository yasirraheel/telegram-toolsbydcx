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
<link rel="stylesheet" href="../assets/site.css"></head><body class="login-wrap">
<div class="box"><h1><a href="../" title="Home" style="color:#1f2937;text-decoration:none">🛒 Tools<span style="color:#ff6622">ByDcx</span> Admin</a></h1><p class="mut">Sign in to manage your Telegram store bots.</p>
<?php foreach (take_flash() as $f): ?><div class="flash"><?= esc($f['msg']) ?></div><?php endforeach; ?>
<form method="post" action="index.php?page=login">
<label>Username</label><input type="text" name="username" required autofocus>
<label>Password</label><input type="password" name="password" required>
<button type="submit">Log in</button>
</form></div></body></html>
