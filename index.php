<?php require 'lib.php';
if (!empty($_GET['email'])) { header('Location: inbox.php?email=' . urlencode(strtolower(trim($_GET['email'])))); exit; }
shell_start('Home', 'home'); ?>
<section class="hero2">
  <div>
    <span class="badge b-primary"><i class="fa-solid fa-bolt"></i> Simple • Fast • Reliable</span>
    <h1>Your email inbox, <span class="grad-text">simplified.</span></h1>
    <p class="lead">Open any authorized Outlook mailbox and read the latest messages and verification codes in seconds — no sign-in needed.</p>
    <form id="open" class="hero-form" novalidate onsubmit="const v=this.email.value.trim();const bad=!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v);document.getElementById('e').classList.toggle('hidden',!bad);if(bad){this.email.focus();return false}">
      <div class="field-wrap" style="flex:1"><i class="fa-regular fa-envelope"></i><label for="email" class="sr-only">Email address</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email" maxlength="255" placeholder="user@outlook.com" class="input" aria-describedby="e"></div>
      <button class="btn btn-primary btn-lg"><i class="fa-solid fa-inbox"></i> Open Inbox</button>
    </form>
    <p id="e" class="input-err hidden" role="alert">Please enter a valid email address.</p>
    <div class="trust"><span><i class="fa-solid fa-circle-check"></i>Encrypted credentials</span><span><i class="fa-solid fa-circle-check"></i>HTML mail view</span><span><i class="fa-solid fa-circle-check"></i>One-tap code copy</span></div>
  </div>
  <div class="preview" aria-hidden="true">
    <div class="bar"><span></span><span></span><span></span></div>
    <div class="pv-item hl"><div class="avatar" style="background:#1877F2">F</div><div style="flex:1;min-width:0"><div class="row between"><b class="small">Facebook</b><span class="small muted">2m ago</span></div><div class="small truncate">Your confirmation code</div></div></div>
    <div class="pv-code"><div><div class="small" style="opacity:.8">Verification code</div><div class="code">56406</div></div><span class="btn btn-sm" style="background:rgba(255,255,255,.18);color:#fff"><i class="fa-regular fa-copy"></i> Copy</span></div>
    <div class="pv-item" style="margin-top:10px"><div class="avatar" style="background:#10B981">M</div><div style="flex:1;min-width:0"><div class="row between"><b class="small">Microsoft</b><span class="small muted">1h ago</span></div><div class="small muted truncate">Security info was added</div></div></div>
    <div class="pv-item"><div class="avatar" style="background:#F59E0B">A</div><div style="flex:1;min-width:0"><div class="row between"><b class="small">Amazon</b><span class="small muted">3h ago</span></div><div class="small muted truncate">Your order has shipped</div></div></div>
  </div>
</section>
<section class="section"><div class="section-head"><span class="eyebrow">Features</span><h2>Everything you need, nothing you don't</h2><p>A focused tool for reading mail and codes quickly.</p></div>
<div class="grid g4">
  <?php foreach ([['fa-bolt','Fast Inbox','Messages load in a moment, with smart caching.'],['fa-envelope-open-text','Rich Mail','HTML emails shown safely, with a plain text option.'],['fa-key','Code Detection','Verification codes highlighted with one-tap copy.'],['fa-shield-halved','Secure Access','Credentials are encrypted and never displayed.']] as [$i,$t,$d]): ?>
  <div class="card card-pad lift"><div class="icon-box"><i class="fa-solid <?= $i ?>"></i></div><h3 style="margin-top:14px"><?= $t ?></h3><p class="muted small" style="margin-top:4px"><?= $d ?></p></div>
  <?php endforeach; ?>
</div></section>
<section class="section"><div class="section-head"><span class="eyebrow">How it works</span><h2>Three simple steps</h2></div>
<div class="grid g3">
  <?php foreach ([['fa-at','Enter the address','Type the Outlook email your administrator added.'],['fa-inbox','Open the inbox','The latest messages load instantly.'],['fa-copy','Copy your code','Tap Copy Code and you are done.']] as $k => [$i,$t,$d]): ?>
  <div class="card stepcard lift"><span class="n"><?= $k+1 ?></span><div class="icon-box"><i class="fa-solid <?= $i ?>"></i></div><h3 style="margin-top:14px"><?= $t ?></h3><p class="muted small" style="margin-top:4px"><?= $d ?></p></div>
  <?php endforeach; ?>
</div></section>
<section class="cta"><div><h2>Ready to check your mail?</h2><p>Enter your Outlook address and see your latest messages instantly.</p></div>
  <a href="#open" class="btn btn-secondary btn-lg" onclick="setTimeout(()=>document.getElementById('email').focus(),300)"><i class="fa-solid fa-inbox"></i> Open Inbox</a></section>
<?php shell_end();
