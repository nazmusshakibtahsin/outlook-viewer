<?php require '../lib.php'; $err = '';
if (is_admin()) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (rate_limited()) $err = 'Too many attempts. Please wait a minute.';
  else {
    $s = db()->prepare('SELECT * FROM admins WHERE username=?'); $s->execute([trim($_POST['u'] ?? '')]); $a = $s->fetch();
    if ($a && password_verify($_POST['p'] ?? '', $a['password_hash'])) { session_regenerate_id(true); $_SESSION['admin_id'] = $a['id']; header('Location: index.php'); exit; }
    $err = 'Wrong username or password.';
  }
}
doc_start('Admin Login'); ?>
<main class="auth-wrap"><div class="auth-card fade">
  <div style="text-align:center;margin-bottom:20px"><a href="../" class="brand" style="justify-content:center"><span class="logo"><i class="fa-solid fa-envelope-open-text"></i></span>Inbox Viewer</a></div>
  <div class="card card-pad" style="padding:28px">
    <h1 style="font-size:20px">Admin Login</h1><p class="muted small" style="margin-top:4px">Sign in to manage mailboxes.</p>
    <?php if ($err): ?><div class="alert a-danger" style="margin-top:16px" role="alert"><i class="fa-solid fa-circle-exclamation"></i><?= h($err) ?></div><?php endif; ?>
    <form method="post" class="stack" style="margin-top:20px" onsubmit="const b=this.querySelector('button[type=submit]');b.disabled=true;b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Signing in…'">
      <div><label class="label" for="u">Username</label><div class="field-wrap"><i class="fa-regular fa-user"></i><input id="u" name="u" class="input" required autocomplete="username" value="<?= h($_POST['u'] ?? '') ?>"></div></div>
      <div><label class="label" for="p">Password</label><div class="field-wrap"><i class="fa-solid fa-lock"></i><input id="p" name="p" type="password" class="input" required autocomplete="current-password" style="padding-right:44px">
        <button type="button" class="btn btn-ghost btn-icon btn-sm" data-toggle-pw="p" aria-label="Show password" style="position:absolute;right:4px;top:5px"><i class="fa-regular fa-eye"></i></button></div></div>
      <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fa-solid fa-right-to-bracket"></i> Login</button>
    </form>
  </div>
  <p class="small muted" style="text-align:center;margin-top:16px"><a href="../"><i class="fa-solid fa-arrow-left"></i> Back to home</a></p>
</div></main></body></html>
