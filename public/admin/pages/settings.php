<?php
// Settings: edit bot_settings keys + welcome banner upload (public/assets/banner.png or .gif).
$keys = [
    'points_rate' => ['label' => 'Points rate ($1 = X points)', 'type' => 'number', 'step' => '0.1', 'def' => '10'],
    'pkr_rate' => ['label' => 'PKR rate ($1 = Rs. X)', 'type' => 'number', 'step' => '1', 'def' => '300'],
    'referral_reward' => ['label' => 'Referral reward (points to BOTH sides)', 'type' => 'number', 'step' => '0.1', 'def' => '0.1'],
    'referral_milestone' => ['label' => 'Referral milestone (refs)', 'type' => 'number', 'step' => '1', 'def' => '20'],
    'referral_milestone_reward' => ['label' => 'Referral milestone reward (points)', 'type' => 'number', 'step' => '0.1', 'def' => '10'],
    'force_join_channel' => ['label' => 'Force-join channel (e.g. @channel — empty = off)', 'type' => 'text', 'def' => ''],
    'methods_channel' => ['label' => 'Methods channel', 'type' => 'text', 'def' => ''],
    'updates_channel' => ['label' => 'Updates channel', 'type' => 'text', 'def' => ''],
    'welcome_text' => ['label' => 'Welcome text ({user_id} is replaced)', 'type' => 'textarea', 'def' => ''],
];
$bannerPng = __DIR__ . '/../../assets/banner.png';
$bannerGif = __DIR__ . '/../../assets/banner.gif';
// The bot prefers the animated GIF when present, else the PNG.
$bannerPath = is_file($bannerGif) ? $bannerGif : $bannerPng;
$bannerUrl = '../assets/' . basename($bannerPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (post_str('action') === 'save_settings') {
        foreach ($keys as $k => $meta) {
            $v = trim((string)($_POST['s_' . $k] ?? ''));
            set_setting($botId, $k, $v === '' ? $meta['def'] : $v);
        }
        flash('Settings saved.');
    } elseif (post_str('action') === 'upload_banner') {
        $f = $_FILES['banner'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('Banner upload failed (no file or upload error).', 'err');
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            flash('Banner must be under 5 MB.', 'err');
        } else {
            $data = file_get_contents($f['tmp_name']);
            $img = ($data === false) ? false : @imagecreatefromstring($data);
            if ($img === false) {
                flash('That file is not a valid image (PNG/JPG/GIF).', 'err');
            } elseif (str_starts_with($data, 'GIF')) {
                // Animated GIF: keep the animation, save as banner.gif.
                if (!is_dir(dirname($bannerGif))) mkdir(dirname($bannerGif), 0755, true);
                if (file_put_contents($bannerGif, $data) !== false) {
                    @unlink($bannerPng);
                    flash('Animated banner uploaded (saved as public/assets/banner.gif).');
                } else flash('Could not save the banner file.', 'err');
                imagedestroy($img);
            } else {
                // Normalise to PNG at a sane size (max 1280 wide) for the welcome banner.
                $w = imagesx($img); $h = imagesy($img);
                if ($w > 1280) {
                    $nw = 1280; $nh = (int)round($h * 1280 / $w);
                    $resized = imagecreatetruecolor($nw, $nh);
                    imagealphablending($resized, false); imagesavealpha($resized, true);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    imagedestroy($img); $img = $resized;
                }
                if (!is_dir(dirname($bannerPng))) mkdir(dirname($bannerPng), 0755, true);
                if (imagepng($img, $bannerPng)) {
                    @unlink($bannerGif);
                    flash('Banner uploaded (saved as public/assets/banner.png).');
                } else flash('Could not save the banner file.', 'err');
                imagedestroy($img);
            }
        }
    }
    redirect('index.php?page=settings');
}

admin_head('Settings', 'settings', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0">⚙️ Bot settings</h3>
<form method="post"><input type="hidden" name="action" value="save_settings"><?= csrf_field() ?>
<?php foreach ($keys as $k => $meta): ?>
<label><?= esc($meta['label']) ?></label>
<?php if (($meta['type'] ?? '') === 'textarea'): ?>
<textarea name="s_<?= esc($k) ?>"><?= esc((string)setting($botId, $k, $meta['def'])) ?></textarea>
<?php else: ?>
<input type="<?= esc($meta['type']) ?>" <?= isset($meta['step']) ? 'step="' . esc($meta['step']) . '"' : '' ?> name="s_<?= esc($k) ?>" value="<?= esc((string)setting($botId, $k, $meta['def'])) ?>">
<?php endif; ?>
<?php endforeach; ?>
<button type="submit" style="margin-top:12px">Save settings</button></form></div>

<div class="card"><h3 style="margin-top:0">🖼️ Welcome banner</h3>
<?php if (is_file($bannerPath)): ?>
<p><img src="<?= esc($bannerUrl) ?>?v=<?= (int)filemtime($bannerPath) ?>" alt="Current banner" style="max-width:100%;border-radius:10px;border:1px solid #e5e7eb"></p>
<p class="mut">Current banner: public/assets/<?= esc(basename($bannerPath)) ?> (<?= (int)filesize($bannerPath) ?> bytes)</p>
<?php else: ?><p class="mut">No banner uploaded yet.</p><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_banner"><?= csrf_field() ?>
<label>Upload new banner (PNG/JPG/GIF, max 5 MB — GIFs keep their animation)</label>
<input type="file" name="banner" accept="image/png,image/jpeg,image/gif" required style="padding:6px">
<button type="submit" style="margin-top:12px">Upload banner</button></form></div>
<?php admin_foot(); ?>
