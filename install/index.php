<?php
// ধাপে ধাপে ইনস্টলার: ডেটাবেস যুক্ত করা, টেবিল বানানো, অ্যাডমিন বানানো
session_start();
$root = dirname(__DIR__);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$done = file_exists("$root/installed.lock");
$err = ''; $ok = false;
$checks = [
  'PHP 7.4+' => version_compare(PHP_VERSION, '7.4', '>='),
  'PDO MySQL' => extension_loaded('pdo_mysql'),
  'cURL' => extension_loaded('curl'),
  'OpenSSL' => extension_loaded('openssl'),
  'Folder writable' => is_writable($root),
];
$allOk = !in_array(false, $checks, true);

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST' && $allOk) {
  $f = array_map('trim', $_POST);
  try {
    if (strlen($_POST['admin_pass'] ?? '') < 8) throw new Exception('Admin password must be 8+ characters.');
    if (($f['admin_user'] ?? '') === '') throw new Exception('Admin username is required.');
    $pdo = new PDO("mysql:host={$f['db_host']};charset=utf8mb4", $f['db_user'], $_POST['db_pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $f['db_name'])) throw new Exception('Database name may only contain letters, numbers and _.');
    try { $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$f['db_name']}` CHARACTER SET utf8mb4"); } catch (Exception $e) { /* শেয়ার্ড হোস্টিংয়ে আগে থেকেই বানানো থাকে */ }
    $pdo->exec("USE `{$f['db_name']}`");
    foreach (array_filter(array_map('trim', explode(';', file_get_contents("$root/schema.sql")))) as $sql) $pdo->exec($sql);
    $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')
        ->execute([$f['admin_user'], password_hash($_POST['admin_pass'], PASSWORD_DEFAULT)]);
    $cfg = [
      'db_host' => $f['db_host'], 'db_name' => $f['db_name'], 'db_user' => $f['db_user'], 'db_pass' => $_POST['db_pass'] ?? '',
      'encryption_key' => bin2hex(random_bytes(32)),
      'mailgen_endpoint' => 'https://mailgen.shop/api/get-inbox',
      'rate_limit_per_min' => 20, 'cache_minutes' => 60,
    ];
    if (file_put_contents("$root/config.php", "<?php\n// ইনস্টলার দিয়ে তৈরি। encryption_key কখনো বদলাবেন না।\nreturn " . var_export($cfg, true) . ";\n") === false) throw new Exception('Could not write config.php');
    file_put_contents("$root/installed.lock", date('c'));
    $ok = true;
  } catch (Exception $e) { $err = $e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install · Inbox Viewer</title>
<?php require_once dirname(__DIR__) . '/theme.php'; theme_assets(); ?></head><body>
<main class="auth-wrap"><div class="auth-card fade" style="max-width:560px">
  <div style="text-align:center;margin-bottom:20px"><span class="brand" style="justify-content:center"><span class="logo"><i class="fa-solid fa-envelope-open-text"></i></span>Inbox Viewer</span><p class="muted small" style="margin-top:6px">Installation wizard</p></div>
  <?php $labels = ['Requirements','Database','Configuration','Installation','Complete']; $cur = ($ok || $done) ? 4 : ($err ? 1 : 0); ?>
  <div class="steps" id="steps" aria-label="Progress"><?php foreach ($labels as $i => $l): ?><div class="step <?= $i < $cur ? 'done' : ($i === $cur ? 'on' : '') ?>" data-s="<?= $i ?>"><div class="bar"></div><span><?= $i+1 ?>. <?= $l ?></span></div><?php endforeach; ?></div>
<?php if ($ok || $done): ?>
  <div class="card card-pad" style="text-align:center;padding:32px">
    <div class="icon-box" style="width:56px;height:56px;margin:0 auto;background:var(--success-soft);color:var(--success);font-size:22px"><i class="fa-solid fa-check"></i></div>
    <h1 style="font-size:20px;margin-top:14px"><?= $ok ? 'Installation complete' : 'Already installed' ?></h1>
    <p class="muted" style="margin-top:6px">Your inbox viewer is ready to use.</p>
    <div class="alert a-warning" style="margin-top:18px;text-align:left"><i class="fa-solid fa-shield-halved"></i><div>For security, delete the <b>install</b> folder from your server.</div></div>
    <div class="row" style="justify-content:center;margin-top:18px"><a href="../admin/" class="btn btn-primary"><i class="fa-solid fa-gauge"></i> Open Admin Panel</a><a href="../" class="btn btn-secondary"><i class="fa-solid fa-house"></i> Open site</a></div>
  </div>
<?php else: ?>
  <?php if ($err): ?><div class="alert a-danger" role="alert" style="margin-bottom:16px"><i class="fa-solid fa-circle-exclamation"></i><div><b>Installation failed</b><div class="small"><?= $h($err) ?></div></div></div><?php endif; ?>
  <form method="post" id="wiz" novalidate>
    <section class="card pane" data-p="0"><div class="card-head"><h2><i class="fa-solid fa-server muted"></i> Server requirements</h2></div>
      <ul class="check-list card-pad" style="padding-top:4px;padding-bottom:4px"><?php foreach ($checks as $n => $v): ?><li><i class="fa-solid <?= $v ? 'fa-circle-check' : 'fa-circle-xmark' ?>" style="color:var(--<?= $v ? 'success' : 'danger' ?>)"></i><span style="flex:1"><?= $h($n) ?></span><span class="badge <?= $v ? 'b-success' : 'b-danger' ?>"><?= $v ? 'OK' : 'Missing' ?></span></li><?php endforeach; ?></ul>
      <div class="card-pad" style="border-top:1px solid var(--border)"><?php if (!$allOk): ?><div class="alert a-danger" style="margin-bottom:12px"><i class="fa-solid fa-triangle-exclamation"></i>Fix the missing items on your hosting, then reload.</div><?php endif; ?>
        <button type="button" class="btn btn-primary btn-block" data-next <?= $allOk ? '' : 'disabled' ?>>Continue <i class="fa-solid fa-arrow-right"></i></button></div></section>
    <section class="card pane hidden" data-p="1"><div class="card-head"><h2><i class="fa-solid fa-database muted"></i> Database connection</h2></div>
      <div class="card-pad stack">
        <div><label class="label" for="db_host">Host</label><input class="input" id="db_host" name="db_host" value="<?= $h($_POST['db_host'] ?? 'localhost') ?>" required></div>
        <div><label class="label" for="db_name">Database name</label><input class="input" id="db_name" name="db_name" value="<?= $h($_POST['db_name'] ?? '') ?>" required pattern="[A-Za-z0-9_]+"><p class="input-err hidden">Letters, numbers and _ only.</p></div>
        <div class="grid g2" style="gap:12px"><div><label class="label" for="db_user">Username</label><input class="input" id="db_user" name="db_user" value="<?= $h($_POST['db_user'] ?? '') ?>" required><p class="input-err hidden">Required.</p></div>
          <div><label class="label" for="db_pass">Password</label><input class="input" id="db_pass" name="db_pass" type="password"></div></div>
        <div class="row between"><button type="button" class="btn btn-ghost" data-prev><i class="fa-solid fa-arrow-left"></i> Back</button><button type="button" class="btn btn-primary" data-next>Continue <i class="fa-solid fa-arrow-right"></i></button></div></div></section>
    <section class="card pane hidden" data-p="2"><div class="card-head"><h2><i class="fa-solid fa-user-shield muted"></i> Admin account</h2></div>
      <div class="card-pad stack">
        <div><label class="label" for="admin_user">Username</label><input class="input" id="admin_user" name="admin_user" value="<?= $h($_POST['admin_user'] ?? 'admin') ?>" required><p class="input-err hidden">Required.</p></div>
        <div><label class="label" for="admin_pass">Password</label><div class="field-wrap"><input class="input" id="admin_pass" name="admin_pass" type="password" required minlength="8" style="padding-left:12px;padding-right:44px"><button type="button" class="btn btn-ghost btn-icon btn-sm" data-toggle-pw="admin_pass" aria-label="Show password" style="position:absolute;right:4px;top:5px"><i class="fa-regular fa-eye"></i></button></div><p class="input-err hidden">At least 8 characters.</p></div>
        <div class="alert a-info"><i class="fa-solid fa-key"></i>A secure encryption key is generated automatically.</div>
        <div class="row between"><button type="button" class="btn btn-ghost" data-prev><i class="fa-solid fa-arrow-left"></i> Back</button><button type="button" class="btn btn-primary" data-next>Continue <i class="fa-solid fa-arrow-right"></i></button></div></div></section>
    <section class="card pane hidden" data-p="3"><div class="card-head"><h2><i class="fa-solid fa-download muted"></i> Ready to install</h2></div>
      <div class="card-pad stack"><p class="muted">The installer will create the tables, your admin account and the configuration file.</p>
        <ul class="check-list" id="summary"></ul>
        <div class="row between"><button type="button" class="btn btn-ghost" data-prev><i class="fa-solid fa-arrow-left"></i> Back</button><button type="submit" class="btn btn-primary" id="go"><i class="fa-solid fa-rocket"></i> Install now</button></div></div></section>
  </form>
<script>
let p = <?= $err ? 1 : 0 ?>; const panes = [...document.querySelectorAll('.pane')], steps = [...document.querySelectorAll('.step')];
function show(n) { p = n; panes.forEach(x => x.classList.toggle('hidden', +x.dataset.p !== n)); steps.forEach((s, i) => { s.className = 'step ' + (i < n ? 'done' : i === n ? 'on' : ''); });
  if (n === 3) { const v = id => document.getElementById(id).value; document.getElementById('summary').innerHTML = [['fa-server','Host',v('db_host')],['fa-database','Database',v('db_name')],['fa-user','DB user',v('db_user')],['fa-user-shield','Admin',v('admin_user')]].map(([i,l,x]) => `<li><i class="fa-solid ${i} muted"></i><span style="flex:1">${l}</span><b class="mono small"></b></li>`).join('');
    document.querySelectorAll('#summary b').forEach((b, i) => b.textContent = [v('db_host'), v('db_name'), v('db_user'), v('admin_user')][i]); } }
function valid(n) { let ok = true; panes[n].querySelectorAll('input[required],input[pattern]').forEach(i => { const bad = !i.checkValidity(); const e = i.closest('div').parentElement.querySelector('.input-err') || i.parentElement.querySelector('.input-err'); if (e) e.classList.toggle('hidden', !bad); i.style.borderColor = bad ? 'var(--danger)' : ''; if (bad && ok) { i.focus(); ok = false; } }); return ok; }
document.addEventListener('click', e => { if (e.target.closest('[data-next]') && valid(p)) show(p + 1); if (e.target.closest('[data-prev]')) show(p - 1); });
document.getElementById('wiz').addEventListener('submit', e => { const g = document.getElementById('go'); g.disabled = true; g.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Installing…'; });
show(p);
</script>
<?php endif; ?>
</div></main></body></html>
