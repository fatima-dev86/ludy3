<?php
// Owner page: turn the special offer on/off and change the percentage.
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

$authFile = DATA_DIR . '/admin.php';
$hash = null;
if (is_file($authFile)) { $a = include $authFile; $hash = is_array($a) ? ($a['hash'] ?? null) : null; }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$msg = ''; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $err = 'The page expired. Please try again.';
    } elseif ($act === 'setup' && !$hash) {
        $p = (string)($_POST['password'] ?? '');
        if (strlen($p) < 8) { $err = 'Please use at least 8 characters.'; }
        else {
            ensure_data_dir();
            $code = "<?php\nreturn " . var_export(['hash' => password_hash($p, PASSWORD_DEFAULT)], true) . ";\n";
            if (@file_put_contents($authFile, $code, LOCK_EX) === false) { $err = 'Could not save the password. Make sure the data folder is writable.'; }
            else { $hash = password_hash($p, PASSWORD_DEFAULT); $_SESSION['in'] = true; session_regenerate_id(true); $msg = 'Password created. You are signed in.'; }
        }
    } elseif ($act === 'login' && $hash) {
        if (password_verify((string)($_POST['password'] ?? ''), $hash)) { session_regenerate_id(true); $_SESSION['in'] = true; }
        else { sleep(1); $err = 'Wrong password.'; }
    } elseif ($act === 'delete_msg' && !empty($_SESSION['in'])) {
        delete_message((string)($_POST['id'] ?? '')); $msg = 'Message deleted.';
    } elseif ($act === 'logout') {
        $_SESSION = []; session_destroy(); header('Location: admin.php'); exit;
    } elseif ($act === 'save' && !empty($_SESSION['in'])) {
        $on  = (($_POST['offer'] ?? '') === 'on');
        $pct = (int)($_POST['percent'] ?? 30);
        if ($pct < 1 || $pct > 90) { $err = 'The percentage must be between 1 and 90.'; }
        else { $msg = save_settings($on, $pct) ? 'Saved. The website now shows the new prices.' : 'Could not save. Make sure the data folder is writable.'; }
    }
}
$in = !empty($_SESSION['in']);
$S = settings();
$messages = $in ? load_messages() : [];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Lady English – Owner page</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:#F2E6D0;color:#3A2E1E;line-height:1.6}
.box{max-width:620px;margin:40px auto;padding:28px;background:#FFFDF8;border:1px solid #DCC9A5;border-radius:22px;box-shadow:10px 10px 0 #F5C431}
h1{font-size:1.6rem;margin:0 0 6px} p{margin:.4rem 0 1rem} label{display:block;font-weight:700;margin:14px 0 6px}
input[type=password],input[type=number]{width:100%;padding:12px;border:1px solid #DCC9A5;border-radius:12px;font:inherit;box-sizing:border-box}
.row{display:flex;gap:18px;flex-wrap:wrap} .row label{font-weight:600;display:flex;gap:8px;align-items:center;margin:6px 0}
button{margin-top:18px;padding:13px 26px;border:0;border-radius:999px;background:#F5C431;color:#3A2E1E;font:inherit;font-weight:700;cursor:pointer}
button.link{background:none;text-decoration:underline;padding:0;margin:0;font-weight:600}
.ok{background:#F5C431;padding:10px 14px;border-radius:12px;font-weight:700} .bad{border:2px solid #D9A410;padding:10px 14px;border-radius:12px;font-weight:700}
a{color:inherit}
.msg{border-top:1px solid #DCC9A5;padding:14px 0} .msg b{font-size:1.05rem} .msg small{color:#6E5E47} .msg p{white-space:pre-wrap;word-break:break-word;margin:.5rem 0}
button.del{margin:0;padding:6px 14px;font-size:.9rem;background:#FFFDF8;border:1px solid #DCC9A5}
</style></head><body><div class="box">
<h1>Lady English – Owner page</h1>
<?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="bad"><?= h($err) ?></p><?php endif; ?>

<?php if (!$hash): ?>
  <p>First time here. Choose a password for this page (at least 8 characters).</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="setup">
  <label for="p">New password</label><input id="p" type="password" name="password" minlength="8" required autocomplete="new-password">
  <button type="submit">Create password</button></form>

<?php elseif (!$in): ?>
  <p>Please sign in.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="login">
  <label for="p">Password</label><input id="p" type="password" name="password" required autocomplete="current-password">
  <button type="submit">Sign in</button></form>

<?php else: ?>
  <p>Control the special offer. The website changes right after you press Save.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save">
  <label>Special offer</label>
  <div class="row">
    <label><input type="radio" name="offer" value="on" <?= $S['offerActive'] ? 'checked' : '' ?>> ON (discounted prices)</label>
    <label><input type="radio" name="offer" value="off" <?= !$S['offerActive'] ? 'checked' : '' ?>> OFF (original prices)</label>
  </div>
  <label for="pc">Discount percentage</label>
  <input id="pc" type="number" name="percent" min="1" max="90" value="<?= (int)$S['offerPercent'] ?>" required>
  <button type="submit">Save</button></form>
  <h2 style="font-size:1.3rem;margin:26px 0 4px">Messages from visitors (<?= count($messages) ?>)</h2>
  <p><small>Email notifications are <b><?= email_configured() ? 'ON' : 'OFF' ?></b>. <?= email_configured() ? '' : 'Messages still arrive here. To also get an email, fill in config.php.' ?></small></p>
  <?php if (!$messages): ?><p>No messages yet.</p><?php endif; ?>
  <?php foreach ($messages as $m): ?>
    <div class="msg">
      <b><?= h($m['name'] ?? '') ?></b>
      <?php if (!empty($m['email'])): ?> · <a href="mailto:<?= h($m['email']) ?>"><?= h($m['email']) ?></a><?php endif; ?>
      <br><small><?= h($m['time'] ?? '') ?></small>
      <p><?= h($m['message'] ?? '') ?></p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_msg"><input type="hidden" name="id" value="<?= h($m['id'] ?? '') ?>"><button class="del" type="submit" onclick="return confirm('Delete this message?')">Delete</button></form>
    </div>
  <?php endforeach; ?>
  <p style="margin-top:22px"><a href="index.php#courses" target="_blank" rel="noopener">See the courses on the website</a></p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="logout"><button class="link" type="submit">Sign out</button></form>
<?php endif; ?>
</div></body></html>
