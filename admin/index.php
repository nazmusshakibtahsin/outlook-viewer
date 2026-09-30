<?php require '../lib.php'; require_admin(); $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf(); $act = $_POST['act'] ?? '';
  if ($act === 'add') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$_POST['rt'] || !$_POST['cid']) $msg = 'Email, refresh token and client ID are required.';
    else {
      db()->prepare('INSERT INTO mailboxes (email, enc_refresh_token, enc_client_id, enc_client_secret) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE enc_refresh_token=VALUES(enc_refresh_token), enc_client_id=VALUES(enc_client_id), enc_client_secret=VALUES(enc_client_secret), status="active"')
        ->execute([$email, encrypt_secret(trim($_POST['rt'])), encrypt_secret(trim($_POST['cid'])), encrypt_secret(trim($_POST['cs'] ?? ''))]);
      log_activity($email, 'saved'); $msg = "Saved $email";
    }
  } elseif ($act === 'bulk') {
    $n = 0;
    foreach (preg_split('/\r?\n/', $_POST['lines'] ?? '') as $line) {
      $p = array_map('trim', explode('|', $line));
      if (count($p) >= 3 && filter_var($p[0], FILTER_VALIDATE_EMAIL)) {
        db()->prepare('REPLACE INTO mailboxes (email, enc_refresh_token, enc_client_id, enc_client_secret) VALUES (?,?,?,?)')
          ->execute([strtolower($p[0]), encrypt_secret($p[1]), encrypt_secret($p[2]), encrypt_secret($p[3] ?? '')]); $n++;
      }
    }
    $msg = "Imported $n mailboxes";
  } elseif ($act === 'toggle') {
    db()->prepare("UPDATE mailboxes SET status=IF(status='active','disabled','active') WHERE id=?")->execute([(int)$_POST['id']]);
  } elseif ($act === 'delete') {
    db()->prepare('DELETE FROM mailboxes WHERE id=?')->execute([(int)$_POST['id']]); $msg = 'Deleted';
  }
}
$q = trim($_GET['q'] ?? '');
$s = db()->prepare('SELECT id, email, status, last_checked_at FROM mailboxes WHERE email LIKE ? ORDER BY id DESC LIMIT 500'); $s->execute(["%$q%"]);
$rows = $s->fetchAll();
$logs = db()->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT 15')->fetchAll();
$total = db()->query('SELECT COUNT(*) FROM mailboxes')->fetchColumn();
$active = db()->query("SELECT COUNT(*) FROM mailboxes WHERE status='active'")->fetchColumn();
$today = db()->query("SELECT COUNT(*) FROM activity_log WHERE action='fetch_ok' AND DATE(created_at)=CURDATE()")->fetchColumn();
$failToday = db()->query("SELECT COUNT(*) FROM activity_log WHERE action='fetch_fail' AND DATE(created_at)=CURDATE()")->fetchColumn();
$lastOk = db()->query("SELECT action, created_at FROM activity_log WHERE action IN ('fetch_ok','fetch_fail') ORDER BY id DESC LIMIT 1")->fetch();
$mailState = !$lastOk ? ['No data yet','b-muted'] : ($lastOk['action']==='fetch_ok' ? ['Connected','b-success'] : ['Warning','b-warning']);
$c = csrf(); shell_start('Admin Dashboard', 'admin'); ?>
<?php crumbs(['Admin', 'Dashboard']); ?>
<div class="page-head"><div><h1>Admin Dashboard</h1><p class="muted">Mailboxes, system health and recent activity.</p></div>
  <a href="#add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add mailbox</a></div>
<?php if ($msg): ?><div class="alert a-info" role="status" style="margin-bottom:16px"><i class="fa-solid fa-circle-info"></i><?= h($msg) ?></div><script>addEventListener('DOMContentLoaded',()=>toast(<?= json_encode($msg) ?>))</script><?php endif; ?>
<div class="grid g4">
  <?php foreach ([['fa-inbox','Total Mailboxes',$total,''],['fa-circle-check','Active',$active,''],['fa-envelope','Fetches Today',$today,$failToday?"$failToday failed":''],['fa-server','System Status',null,'']] as [$i,$l,$v,$sub]): ?>
  <div class="card card-pad stat lift"><div class="row between"><span class="muted small"><?= $l ?></span><span class="icon-box" style="width:32px;height:32px"><i class="fa-solid <?= $i ?>"></i></span></div>
    <?php if ($v === null): ?><div style="margin-top:12px"><span class="badge b-success"><span class="dot"></span>Healthy</span></div>
    <?php else: ?><div class="num"><?= (int)$v ?></div><?php endif; ?>
    <?php if ($sub): ?><div class="small" style="color:var(--danger)"><?= h($sub) ?></div><?php endif; ?></div>
  <?php endforeach; ?>
</div>
<div class="grid g3" style="margin-top:16px">
  <div class="card" style="grid-column:span 2" id="mailboxes">
    <div class="card-head"><h2>Mailboxes</h2>
      <form class="field-wrap" style="width:220px;max-width:50%"><i class="fa-solid fa-magnifying-glass"></i><label for="q" class="sr-only">Search mailboxes</label><input id="q" name="q" value="<?= h($q) ?>" placeholder="Search…" class="input" style="height:36px"></form></div>
    <?php if (!$rows): ?><div class="empty"><div class="icon-box"><i class="fa-solid fa-inbox"></i></div><h2>No mailboxes<?= $q ? ' found' : ' yet' ?></h2><p class="muted small">Add one using the form below.</p></div>
    <?php else: ?><table class="table"><thead><tr><th>Email</th><th>Credentials</th><th>Status</th><th>Last checked</th><th style="text-align:right">Actions</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr>
        <td><a class="truncate" style="display:block;max-width:260px;color:var(--primary);font-weight:500" target="_blank" href="../inbox.php?email=<?= urlencode($r['email']) ?>"><?= h($r['email']) ?></a></td>
        <td data-l="Credentials" class="muted mono">••••••••</td>
        <td data-l="Status"><?= $r['status']==='active' ? '<span class="badge b-success"><span class="dot"></span>Active</span>' : '<span class="badge b-muted"><span class="dot"></span>Disabled</span>' ?></td>
        <td data-l="Last checked" class="muted small"><?= h($r['last_checked_at'] ?? '—') ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn btn-ghost btn-icon btn-sm" target="_blank" href="../inbox.php?email=<?= urlencode($r['email']) ?>" aria-label="Open inbox"><i class="fa-solid fa-eye"></i></a>
          <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm" aria-label="Enable or disable"><i class="fa-solid fa-power-off"></i></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this mailbox?')"><input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm" style="color:var(--danger)" aria-label="Delete"><i class="fa-solid fa-trash"></i></button></form>
        </td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  </div>
  <div class="card"><div class="card-head"><h2>System Status</h2></div><ul class="check-list card-pad" style="padding-top:6px;padding-bottom:6px">
    <li><i class="fa-solid fa-database muted"></i><span style="flex:1">Database</span><span class="badge b-success"><span class="dot"></span>Connected</span></li>
    <li><i class="fa-solid fa-plug muted"></i><span style="flex:1">Mail connection</span><span class="badge <?= $mailState[1] ?>"><span class="dot"></span><?= $mailState[0] ?></span></li>
    <li><i class="fa-solid fa-lock muted"></i><span style="flex:1">Encryption</span><span class="badge b-success"><span class="dot"></span>Healthy</span></li>
    <li><i class="fa-regular fa-clock muted"></i><span style="flex:1">Last activity</span><span class="small muted"><?= h($logs[0]['created_at'] ?? '—') ?></span></li></ul></div>
</div>
<div class="grid g2" style="margin-top:16px" id="add">
  <form method="post" class="card card-pad stack"><h2><i class="fa-solid fa-plus muted"></i> Add / update mailbox</h2>
    <input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="act" value="add">
    <div><label class="label" for="f-email">Email</label><input id="f-email" name="email" type="email" class="input" required placeholder="user@outlook.com"></div>
    <div><label class="label" for="f-rt">Refresh token</label><input id="f-rt" name="rt" type="password" class="input" required autocomplete="off"></div>
    <div class="grid g2" style="gap:12px"><div><label class="label" for="f-cid">Client ID</label><input id="f-cid" name="cid" type="password" class="input" required autocomplete="off"></div>
      <div><label class="label" for="f-cs">Client secret <span class="muted small">(optional)</span></label><input id="f-cs" name="cs" type="password" class="input" autocomplete="off"></div></div>
    <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save mailbox</button></form>
  <form method="post" class="card card-pad stack"><h2><i class="fa-solid fa-file-import muted"></i> Bulk import</h2>
    <input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="act" value="bulk">
    <div><label class="label" for="f-lines">One mailbox per line</label><textarea id="f-lines" name="lines" rows="7" class="textarea mono small" placeholder="email|refresh_token|client_id|client_secret"></textarea></div>
    <button class="btn btn-secondary"><i class="fa-solid fa-upload"></i> Import</button></form>
</div>
<div class="card" style="margin-top:16px"><div class="card-head"><h2>Recent Activity</h2></div>
  <?php if (!$logs): ?><div class="empty"><p class="muted small">No activity yet.</p></div><?php else: ?>
  <table class="table"><thead><tr><th>Time</th><th>Mailbox</th><th>Event</th><th>Detail</th></tr></thead><tbody>
  <?php foreach ($logs as $l): $b = $l['action']==='fetch_ok'?'b-success':($l['action']==='fetch_fail'?'b-danger':'b-primary'); ?>
    <tr><td class="small muted" data-l="Time"><?= h($l['created_at']) ?></td><td class="truncate" data-l="Mailbox" style="max-width:240px"><?= h($l['email']) ?></td><td data-l="Event"><span class="badge <?= $b ?>"><?= h($l['action']) ?></span></td><td class="small muted" data-l="Detail"><?= h($l['detail']) ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php shell_end();
