<?php
function base_url(): string {
  $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
  return preg_replace('#/(admin|install)$#', '', $d);
}
function theme_assets() { $b = base_url(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= $b ?>/assets/app.css?v=7">
<script src="<?= $b ?>/assets/app.js?v=7" defer></script>
<?php }
function doc_start($title) { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> · Inbox Viewer</title><?php theme_assets(); ?></head><body>
<?php }
// Header + sidebar shell. $active: home|inbox|admin|settings
function shell_start($title, $active = 'home', $right = '') {
  $b = base_url(); $admin = !empty($_SESSION['admin_id']); doc_start($title);
  $items = [['home', 'fa-house', 'Home', "$b/"], ['inbox', 'fa-inbox', 'Inbox', "$b/#open"]];
  // Admin links are only shown to signed-in admins; the public reaches it by typing /admin
  if ($admin) { $items[] = ['admin', 'fa-gauge', 'Dashboard', "$b/admin/"]; $items[] = ['settings', 'fa-gear', 'Mailboxes', "$b/admin/#mailboxes"]; } ?>
<a href="#main" class="sr-only">Skip to content</a>
<header class="header">
  <button class="btn btn-ghost btn-icon menu-btn" data-drawer aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
  <a href="<?= $b ?>/" class="brand"><span class="logo"><i class="fa-solid fa-envelope-open-text"></i></span><span>Inbox Viewer</span></a>
  <div class="spacer"></div><?= $right ?>
  <?php if ($admin): ?><a href="<?= $b ?>/admin/logout.php" class="btn btn-ghost btn-sm" aria-label="Log out"><i class="fa-solid fa-right-from-bracket"></i><span class="hide-sm">Logout</span></a>
  <?php endif; ?>
</header>
<div class="overlay"></div>
<div class="layout">
  <aside class="sidebar" aria-label="Main navigation">
    <div class="row between" style="margin-bottom:8px"><span class="nav-label">Menu</span><button class="btn btn-ghost btn-icon btn-sm menu-btn" data-drawer aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button></div>
    <nav class="nav"><?php foreach ($items as [$k, $i, $l, $u]): ?>
      <a href="<?= $u ?>" class="<?= $k === $active ? 'active' : '' ?>" <?= $k === $active ? 'aria-current="page"' : '' ?>><i class="fa-solid <?= $i ?>"></i><?= $l ?></a>
    <?php endforeach; ?></nav>
    <div class="side-foot"><div class="icon-box" style="width:30px;height:30px;background:#fff;margin-bottom:8px"><i class="fa-solid fa-shield-halved"></i></div><b>Encrypted storage</b><p>Mailbox credentials are never shown or shared.</p></div>
  </aside>
  <main id="main" class="main fade">
<?php }
function shell_end() { ?>
<footer class="footer"><span>&copy; <?= date('Y') ?> Inbox Viewer</span><span><i class="fa-solid fa-lock"></i> Private &amp; secure</span></footer>
</main></div></body></html><?php }
function crumbs(array $items) { $b = base_url(); echo '<nav class="crumbs" aria-label="Breadcrumb"><a href="'.$b.'/"><i class="fa-solid fa-house" style="font-size:11px"></i></a>';
  foreach ($items as $i) echo '<i class="fa-solid fa-chevron-right"></i><span>'.htmlspecialchars($i).'</span>'; echo '</nav>'; }
