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
  } elseif ($act === 'bulk_toggle') {
    $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
    if (!empty($ids)) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      db()->prepare("UPDATE mailboxes SET status=IF(status='active','disabled','active') WHERE id IN ($placeholders)")->execute($ids);
      $msg = count($ids) . " mailbox(es) updated";
    }
  } elseif ($act === 'bulk_delete') {
    $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
    if (!empty($ids)) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      db()->prepare("DELETE FROM mailboxes WHERE id IN ($placeholders)")->execute($ids);
      $msg = count($ids) . " mailbox(es) deleted";
    }
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
$mailState = !$lastOk ? ['No data yet','text-gray-600'] : ($lastOk['action']==='fetch_ok' ? ['Connected','text-green-600'] : ['Warning','text-yellow-600']);
$c = csrf(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Outlook Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --background: 0 0% 100%;
            --foreground: 0 0% 3.6%;
            --primary: 210 40% 96%;
            --primary-foreground: 222 47% 11%;
            --secondary: 210 40% 96%;
            --secondary-foreground: 222 47% 11%;
            --destructive: 0 84.2% 60.2%;
            --destructive-foreground: 0 0% 100%;
            --muted: 210 40% 96%;
            --muted-foreground: 215 16% 47%;
            --accent: 210 40% 96%;
            --accent-foreground: 222 47% 11%;
            --border: 214 32% 91%;
            --input: 214 32% 91%;
            --ring: 222 47% 11%;
            --card: 0 0% 100%;
            --card-foreground: 0 0% 3.6%;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: 222 84% 5%;
                --foreground: 210 40% 98%;
                --primary: 217 33% 17%;
                --primary-foreground: 210 40% 98%;
                --secondary: 217 33% 17%;
                --secondary-foreground: 210 40% 98%;
                --destructive: 0 86.1% 61.2%;
                --destructive-foreground: 0 0% 14.9%;
                --muted: 215 27% 33%;
                --muted-foreground: 215 16% 47%;
                --accent: 217 33% 17%;
                --accent-foreground: 210 40% 98%;
                --border: 217 33% 17%;
                --input: 217 33% 17%;
                --ring: 212 35% 21%;
                --card: 222 84% 5%;
                --card-foreground: 210 40% 98%;
            }
        }

        * {
            @apply transition-colors duration-200;
        }

        body {
            @apply bg-background text-foreground;
        }

        .shadcn-btn {
            @apply inline-flex items-center justify-center gap-2 px-4 py-2 rounded-md font-medium transition-all duration-200;
            @apply focus:outline-none focus:ring-2 focus:ring-offset-2;
        }

        .btn-primary {
            @apply bg-blue-600 text-white hover:bg-blue-700;
        }

        .btn-secondary {
            @apply bg-secondary text-secondary-foreground hover:bg-secondary/80;
        }

        .btn-destructive {
            @apply bg-red-600 text-white hover:bg-red-700;
        }

        .btn-outline {
            @apply border border-border bg-background hover:bg-accent;
        }

        .btn-ghost {
            @apply bg-transparent hover:bg-accent hover:text-accent-foreground;
        }

        .shadcn-card {
            @apply bg-card border border-border rounded-lg shadow-sm;
        }

        .shadcn-input {
            @apply flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm;
            @apply placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-blue-500;
        }

        .shadcn-badge {
            @apply inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold gap-1;
        }

        .badge-success {
            @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200;
        }

        .badge-destructive {
            @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200;
        }

        .badge-warning {
            @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200;
        }

        .badge-default {
            @apply bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-200;
        }

        .stats-grid {
            display: grid;
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 641px) and (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1025px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
    </style>
</head>
<body class="bg-background text-foreground">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-card border-b border-border sticky top-0 z-50 shadow-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="bg-blue-600 text-white p-2 rounded-lg">
                            <i class="fas fa-shield-alt text-lg"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl sm:text-3xl font-bold">Admin Dashboard</h1>
                            <p class="text-xs sm:text-sm text-muted-foreground">Mailbox management</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="../" class="shadcn-btn btn-outline">
                            <i class="fas fa-arrow-left"></i>
                            <span class="hidden sm:inline">Back</span>
                        </a>
                        <a href="logout.php" class="shadcn-btn btn-destructive">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="hidden sm:inline">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- Alert Message -->
            <?php if ($msg): ?>
            <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center gap-2">
                <i class="fas fa-check-circle"></i>
                <span><?= h($msg) ?></span>
                <button onclick="this.parentElement.style.display='none'" class="ml-auto hover:opacity-70">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid mb-8">
                <!-- Total Mailboxes -->
                <div class="shadcn-card p-6 border-l-4 border-l-blue-600">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Total Mailboxes</p>
                            <p class="text-4xl font-bold mt-2"><?= (int)$total ?></p>
                        </div>
                        <div class="bg-blue-100 dark:bg-blue-900 p-4 rounded-lg">
                            <i class="fas fa-inbox text-2xl text-blue-600"></i>
                        </div>
                    </div>
                </div>

                <!-- Active Mailboxes -->
                <div class="shadcn-card p-6 border-l-4 border-l-green-600">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Active</p>
                            <p class="text-4xl font-bold mt-2"><?= (int)$active ?></p>
                            <p class="text-xs text-muted-foreground mt-1"><?= (int)($total - $active) ?> disabled</p>
                        </div>
                        <div class="bg-green-100 dark:bg-green-900 p-4 rounded-lg">
                            <i class="fas fa-circle-check text-2xl text-green-600"></i>
                        </div>
                    </div>
                </div>

                <!-- Fetches Today -->
                <div class="shadcn-card p-6 border-l-4 border-l-purple-600">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Fetches Today</p>
                            <p class="text-4xl font-bold mt-2"><?= (int)$today ?></p>
                            <?php if ($failToday > 0): ?>
                            <p class="text-xs text-red-600 mt-1">
                                <i class="fas fa-exclamation-circle"></i> <?= (int)$failToday ?> failed
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="bg-purple-100 dark:bg-purple-900 p-4 rounded-lg">
                            <i class="fas fa-envelope text-2xl text-purple-600"></i>
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div class="shadcn-card p-6 border-l-4 border-l-yellow-600">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">System Status</p>
                            <p class="text-xl font-bold mt-2 <?= $mailState[1] ?>">
                                <?= $mailState[0] ?>
                            </p>
                            <?php if ($lastOk): ?>
                            <p class="text-xs text-muted-foreground mt-1">Last: <?= date('H:i', strtotime($lastOk['created_at'])) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="bg-yellow-100 dark:bg-yellow-900 p-4 rounded-lg">
                            <i class="fas fa-server text-2xl text-yellow-600"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mailboxes Section -->
            <div class="shadcn-card mb-8 overflow-hidden">
                <div class="bg-blue-600 text-white px-6 py-4">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-bold flex items-center gap-2">
                                <i class="fas fa-envelope-open-text"></i> Mailboxes
                            </h2>
                            <p class="text-blue-100 text-sm mt-1"><?= count($rows) ?> mailbox(es) found</p>
                        </div>
                        <form method="GET" class="w-full sm:w-64">
                            <div class="relative">
                                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search mailboxes..." 
                                       class="w-full px-4 py-2 pr-10 rounded-md text-foreground bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
                                <i class="fas fa-search absolute right-3 top-2.5 text-muted-foreground"></i>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!$rows): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-inbox text-6xl text-muted mb-4"></i>
                    <h3 class="text-xl font-semibold text-foreground mb-2">No mailboxes found</h3>
                    <p class="text-muted-foreground mb-4">Add your first mailbox using the form below</p>
                    <a href="#add" class="inline-block shadcn-btn btn-primary">
                        <i class="fas fa-plus"></i> Add Mailbox
                    </a>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <div class="inline-flex gap-2 p-4 bg-secondary border-b border-border w-full" id="bulkActionBar" style="display: none;">
                        <input type="checkbox" id="selectAllMB" class="cursor-pointer">
                        <span id="selectedCountMB" class="text-sm text-foreground ml-2">0 selected</span>
                        <button onclick="bulkToggleMB()" class="ml-auto shadcn-btn btn-secondary text-sm" id="bulkToggleBtn">
                            <i class="fas fa-toggle-on"></i> Toggle Status
                        </button>
                        <button onclick="bulkDeleteMB()" class="shadcn-btn btn-destructive text-sm" id="bulkDeleteBtn">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-secondary border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">
                                    <input type="checkbox" id="selectAllCheckbox" class="cursor-pointer" onchange="toggleAllMB()">
                                </th>
                                <th class="px-4 py-3 text-left font-semibold">Email</th>
                                <th class="px-4 py-3 text-left font-semibold hidden sm:table-cell">Credentials</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Last Checked</th>
                                <th class="px-4 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="mailboxTableBody">
                            <?php foreach ($rows as $r): ?>
                            <tr class="border-b border-border hover:bg-secondary/50 mailbox-row" data-id="<?= $r['id'] ?>">
                                <td class="px-4 py-3">
                                    <input type="checkbox" class="mailbox-checkbox cursor-pointer" value="<?= $r['id'] ?>" onchange="updateBulkUI()">
                                </td>
                                <td class="px-4 py-3">
                                    <a href="../inbox.php?email=<?= urlencode($r['email']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium truncate block max-w-xs">
                                        <?= h($r['email']) ?>
                                        <i class="fas fa-external-link-alt text-xs ml-1"></i>
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground font-mono hidden sm:table-cell">••••••••</td>
                                <td class="px-4 py-3">
                                    <?php if ($r['status'] === 'active'): ?>
                                    <span class="shadcn-badge badge-success">
                                        <span class="w-2 h-2 bg-green-600 rounded-full"></span> Active
                                    </span>
                                    <?php else: ?>
                                    <span class="shadcn-badge badge-default">
                                        <span class="w-2 h-2 bg-gray-400 rounded-full"></span> Disabled
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground hidden md:table-cell">
                                    <?= h($r['last_checked_at'] ?? '—') ?>
                                </td>
                                <td class="px-4 py-3 text-right space-x-1">
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= $c ?>">
                                        <input type="hidden" name="act" value="toggle">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button class="shadcn-btn btn-secondary text-xs px-2 py-1" title="Toggle status">
                                            <i class="fas fa-toggle-on"></i>
                                        </button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this mailbox?')">
                                        <input type="hidden" name="csrf" value="<?= $c ?>">
                                        <input type="hidden" name="act" value="delete">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button class="shadcn-btn btn-destructive text-xs px-2 py-1" title="Delete mailbox">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Mailbox Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8" id="add">
                <!-- Add Single Mailbox -->
                <div class="shadcn-card p-6 border-t-4 border-t-blue-600">
                    <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <i class="fas fa-plus-circle text-blue-600"></i> Add Mailbox
                    </h3>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf" value="<?= $c ?>">
                        <input type="hidden" name="act" value="add">
                        
                        <div>
                            <label class="block text-sm font-medium mb-2">Email Address</label>
                            <input type="email" name="email" class="shadcn-input w-full" 
                                   required placeholder="user@outlook.com">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">Refresh Token</label>
                            <input type="password" name="rt" class="shadcn-input w-full" 
                                   required autocomplete="off" placeholder="••••••••">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">Client ID</label>
                            <input type="password" name="cid" class="shadcn-input w-full" 
                                   required autocomplete="off" placeholder="••••••••">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">Client Secret <span class="text-muted-foreground text-xs">(optional)</span></label>
                            <input type="password" name="cs" class="shadcn-input w-full" 
                                   autocomplete="off" placeholder="••••••••">
                        </div>

                        <button type="submit" class="w-full shadcn-btn btn-primary">
                            <i class="fas fa-floppy-disk"></i> Save Mailbox
                        </button>
                    </form>
                </div>

                <!-- Bulk Import -->
                <div class="shadcn-card p-6 border-t-4 border-t-purple-600">
                    <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <i class="fas fa-file-upload text-purple-600"></i> Bulk Import
                    </h3>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf" value="<?= $c ?>">
                        <input type="hidden" name="act" value="bulk">
                        
                        <div>
                            <label class="block text-sm font-medium mb-2">One mailbox per line</label>
                            <p class="text-xs text-muted-foreground mb-2">Format: <code class="bg-secondary px-2 py-1 rounded">email|refresh_token|client_id|client_secret</code></p>
                            <textarea name="lines" rows="7" class="shadcn-input w-full font-mono text-sm resize-none" 
                                      placeholder="user1@outlook.com|token1|id1|secret1&#10;user2@outlook.com|token2|id2|secret2"></textarea>
                        </div>

                        <button type="submit" class="w-full shadcn-btn btn-primary">
                            <i class="fas fa-upload"></i> Import Mailboxes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="shadcn-card overflow-hidden">
                <div class="bg-green-600 text-white px-6 py-4">
                    <h2 class="text-2xl font-bold flex items-center gap-2">
                        <i class="fas fa-history"></i> Recent Activity
                    </h2>
                    <p class="text-green-100 text-sm mt-1">Last 15 activities</p>
                </div>

                <?php if (!$logs): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-clipboard-list text-6xl text-muted mb-4"></i>
                    <p class="text-muted-foreground">No activity yet.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-secondary border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Time</th>
                                <th class="px-4 py-3 text-left font-semibold">Mailbox</th>
                                <th class="px-4 py-3 text-left font-semibold">Event</th>
                                <th class="px-4 py-3 text-left font-semibold hidden sm:table-cell">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l):
                                $badgeClass = $l['action']==='fetch_ok' ? 'badge-success' : ($l['action']==='fetch_fail' ? 'badge-destructive' : 'badge-default');
                                $icon = $l['action']==='fetch_ok' ? 'fa-check-circle' : ($l['action']==='fetch_fail' ? 'fa-exclamation-circle' : 'fa-info-circle');
                            ?>
                            <tr class="border-b border-border hover:bg-secondary/50">
                                <td class="px-4 py-3 text-muted-foreground"><?= date('M d, H:i', strtotime($l['created_at'])) ?></td>
                                <td class="px-4 py-3 font-medium truncate max-w-xs"><?= h($l['email']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="shadcn-badge <?= $badgeClass ?>">
                                        <i class="fas <?= $icon ?>"></i> <?= ucfirst(str_replace('_', ' ', $l['action'])) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground hidden sm:table-cell"><?= h($l['detail'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        let selectedMB = new Set();

        function toggleAllMB() {
            const checkboxes = document.querySelectorAll('.mailbox-checkbox');
            const selectAll = document.getElementById('selectAllCheckbox');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
                if (selectAll.checked) {
                    selectedMB.add(parseInt(cb.value));
                } else {
                    selectedMB.delete(parseInt(cb.value));
                }
            });
            updateBulkUI();
        }

        function updateBulkUI() {
            selectedMB.clear();
            document.querySelectorAll('.mailbox-checkbox:checked').forEach(cb => {
                selectedMB.add(parseInt(cb.value));
            });
            
            const bulkBar = document.getElementById('bulkActionBar');
            const countSpan = document.getElementById('selectedCountMB');
            
            if (selectedMB.size > 0) {
                bulkBar.style.display = 'flex';
                countSpan.textContent = selectedMB.size + ' selected';
            } else {
                bulkBar.style.display = 'none';
            }
            
            document.getElementById('selectAllCheckbox').checked = selectedMB.size > 0 && selectedMB.size === document.querySelectorAll('.mailbox-checkbox').length;
        }

        function bulkToggleMB() {
            if (selectedMB.size === 0 || !confirm(`Toggle status for ${selectedMB.size} mailbox(es)?`)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf" value="<?= $c ?>">
                <input type="hidden" name="act" value="bulk_toggle">
                <input type="hidden" name="ids" value="${Array.from(selectedMB).join(',')}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function bulkDeleteMB() {
            if (selectedMB.size === 0 || !confirm(`Delete ${selectedMB.size} mailbox(es)? This cannot be undone.`)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf" value="<?= $c ?>">
                <input type="hidden" name="act" value="bulk_delete">
                <input type="hidden" name="ids" value="${Array.from(selectedMB).join(',')}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        document.addEventListener('DOMContentLoaded', updateBulkUI);
    </script>
</body>
</html>
<?php shell_end(); ?>
